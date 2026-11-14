<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Content;
use App\Models\ContentCreator;
use App\Models\CreatorMonthlyStatement;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\RightsHolderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin CRUD for rights holders ("titulari", stored as content_creators): legal and payment
 * details, an optional login user for the producer portal, and the titles they hold (one
 * holder per title). Saving keeps the reporting fiscal profile in step.
 */
class ContentCreatorController extends ApiController
{
    public function __construct(
        protected AuditLogService $auditLog,
        protected RightsHolderService $rightsHolders,
    ) {}

    /** Fields the admin edits directly, in the order the form shows them. */
    private const HOLDER_FIELDS = [
        'name', 'email', 'company_name', 'person_type', 'idno', 'legal_address', 'iban', 'bank_name',
        'bank_swift', 'is_vat_payer', 'vat_code', 'contact_person', 'phone', 'platform_fee_percent', 'is_active',
    ];

    public function index(): JsonResponse
    {
        $creators = ContentCreator::query()
            ->with(['user:id,name,email', 'contents:id,original_title'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'items' => $creators->map(fn (ContentCreator $c) => [
                'id' => $c->id,
                ...$c->only(self::HOLDER_FIELDS),
                'user' => $c->user ? [
                    'id' => $c->user->id,
                    'name' => $c->user->name,
                    'email' => $c->user->email,
                ] : null,
                'content_count' => $c->contents->count(),
                'contents' => $c->contents->map(fn (Content $ct) => [
                    'id' => $ct->id,
                    'title' => $ct->original_title,
                ])->values(),
            ])->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $payload = $this->validatePayload($request);
        $creator = DB::transaction(function () use ($payload, $request): ContentCreator {
            $creator = ContentCreator::query()->create([
                'user_id' => $payload['user_id'] ?? null,
                ...$this->holderAttributes($payload),
                'platform_fee_percent' => $payload['platform_fee_percent'] ?? 0,
                'is_active' => $payload['is_active'] ?? true,
            ]);
            if (! empty($payload['content_ids'])) {
                $creator->assignTitles($payload['content_ids']);
            }
            $this->rightsHolders->syncFiscalProfile($creator, $request->user()?->id);

            return $creator;
        });

        $this->auditLog->record('content_creator.created', 'content_creator', $creator->id, [], $request->user(), $request);

        return response()->json(['creator' => $creator->fresh(['contents', 'user'])], Response::HTTP_CREATED);
    }

    public function update(Request $request, ContentCreator $creator): JsonResponse
    {
        $payload = $this->validatePayload($request);
        DB::transaction(function () use ($payload, $creator, $request): void {
            $creator->fill([
                'user_id' => array_key_exists('user_id', $payload) ? $payload['user_id'] : $creator->user_id,
                ...$this->holderAttributes($payload, $creator),
                'platform_fee_percent' => $payload['platform_fee_percent'] ?? $creator->platform_fee_percent,
                'is_active' => $payload['is_active'] ?? $creator->is_active,
            ])->save();
            if (array_key_exists('content_ids', $payload)) {
                $creator->assignTitles($payload['content_ids'] ?? []);
            }
            $this->rightsHolders->syncFiscalProfile($creator, $request->user()?->id);
        });

        $this->auditLog->record('content_creator.updated', 'content_creator', $creator->id, [], $request->user(), $request);

        return response()->json(['creator' => $creator->fresh(['contents', 'user'])]);
    }

    public function destroy(Request $request, ContentCreator $creator): JsonResponse
    {
        $id = $creator->id;
        $creator->delete();
        $this->auditLog->record('content_creator.deleted', 'content_creator', $id, [], $request->user(), $request);

        return response()->json([], Response::HTTP_NO_CONTENT);
    }

    public function statements(ContentCreator $creator): JsonResponse
    {
        $statements = CreatorMonthlyStatement::query()
            ->where('content_creator_id', $creator->id)
            ->orderByDesc('month')
            ->get();

        return response()->json([
            'creator_id' => $creator->id,
            'items' => $statements->map(fn (CreatorMonthlyStatement $s) => [
                'id' => $s->id,
                'month' => $s->month,
                'revenue_usd' => $s->revenue_usd,
                'costs_usd' => $s->costs_usd,
                'payout_usd' => $s->payout_usd,
                'profit_usd' => $s->profit_usd,
                'is_locked' => $s->is_locked,
            ])->values(),
        ]);
    }

    /**
     * The submitted legal/payment fields; on update, fields left out keep their value.
     *
     * @return array<string, mixed>
     */
    private function holderAttributes(array $payload, ?ContentCreator $creator = null): array
    {
        $attributes = [];
        foreach (['name', 'email', 'company_name', 'person_type', 'idno', 'legal_address', 'iban', 'bank_name', 'bank_swift', 'is_vat_payer', 'vat_code', 'contact_person', 'phone'] as $field) {
            if (array_key_exists($field, $payload)) {
                $attributes[$field] = is_string($payload[$field]) ? (trim($payload[$field]) ?: null) : $payload[$field];
            } elseif ($creator === null) {
                $attributes[$field] = match ($field) {
                    'person_type' => 'PJ',
                    'is_vat_payer' => false,
                    default => null,
                };
            }
        }
        // Normalised so the fiscal profile compares like for like.
        foreach (['iban', 'bank_swift'] as $field) {
            if (isset($attributes[$field])) {
                $attributes[$field] = strtoupper(preg_replace('/\s+/', '', $attributes[$field]));
            }
        }
        if (array_key_exists('is_vat_payer', $attributes) && ! $attributes['is_vat_payer']) {
            $attributes['vat_code'] = null;
        }

        return $attributes;
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'person_type' => ['sometimes', Rule::in(ContentCreator::PERSON_TYPES)],
            // IDNO (companies) / IDNP (persons) in Moldova; foreign holders use their own tax code.
            'idno' => ['nullable', 'string', 'max:32'],
            'legal_address' => ['nullable', 'string', 'max:500'],
            'iban' => ['nullable', 'string', 'max:64', 'regex:/^\s*[A-Za-z]{2}\d{2}[A-Za-z0-9 ]{10,40}\s*$/'],
            'bank_name' => ['nullable', 'string', 'max:255'],
            'bank_swift' => ['nullable', 'string', 'regex:/^\s*[A-Za-z]{6}[A-Za-z0-9]{2}([A-Za-z0-9]{3})?\s*$/'],
            'is_vat_payer' => ['sometimes', 'boolean'],
            'vat_code' => ['nullable', 'string', 'max:32'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:64'],
            'platform_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'content_ids' => ['nullable', 'array'],
            'content_ids.*' => ['integer'],
        ], [
            'iban.regex' => 'IBAN-ul nu pare valid (ex. MD24AG000225100013104168).',
            'bank_swift.regex' => 'Codul SWIFT/BIC are 8 sau 11 caractere (ex. AGRNMD2X).',
        ]);
    }
}
