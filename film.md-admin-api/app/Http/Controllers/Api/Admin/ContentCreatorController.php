<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\Content;
use App\Models\ContentCreator;
use App\Models\CreatorMonthlyStatement;
use App\Models\User;
use App\Services\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admin CRUD for content_creators (linkable to a User for portal access)
 * plus exposing monthly statements + content assignments.
 */
class ContentCreatorController extends ApiController
{
    public function __construct(
        protected AuditLogService $auditLog,
    ) {}

    public function index(): JsonResponse
    {
        $creators = ContentCreator::query()
            ->with(['user:id,name,email', 'contents:id,original_title'])
            ->orderBy('name')
            ->get();

        return response()->json([
            'items' => $creators->map(fn (ContentCreator $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'email' => $c->email,
                'company_name' => $c->company_name,
                'platform_fee_percent' => $c->platform_fee_percent,
                'is_active' => $c->is_active,
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
        $creator = DB::transaction(function () use ($payload): ContentCreator {
            $creator = ContentCreator::query()->create([
                'user_id' => $payload['user_id'] ?? null,
                'name' => $payload['name'],
                'email' => $payload['email'] ?? null,
                'company_name' => $payload['company_name'] ?? null,
                'platform_fee_percent' => $payload['platform_fee_percent'] ?? 0,
                'is_active' => $payload['is_active'] ?? true,
            ]);
            if (! empty($payload['content_ids'])) {
                $this->syncContents($creator, $payload['content_ids']);
            }

            return $creator;
        });

        $this->auditLog->record('content_creator.created', 'content_creator', $creator->id, [], $request->user(), $request);

        return response()->json(['creator' => $creator->fresh(['contents', 'user'])], Response::HTTP_CREATED);
    }

    public function update(Request $request, ContentCreator $creator): JsonResponse
    {
        $payload = $this->validatePayload($request);
        DB::transaction(function () use ($payload, $creator): void {
            $creator->fill([
                'user_id' => $payload['user_id'] ?? $creator->user_id,
                'name' => $payload['name'] ?? $creator->name,
                'email' => $payload['email'] ?? $creator->email,
                'company_name' => $payload['company_name'] ?? $creator->company_name,
                'platform_fee_percent' => $payload['platform_fee_percent'] ?? $creator->platform_fee_percent,
                'is_active' => $payload['is_active'] ?? $creator->is_active,
            ])->save();
            if (array_key_exists('content_ids', $payload)) {
                $this->syncContents($creator, $payload['content_ids'] ?? []);
            }
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

    private function syncContents(ContentCreator $creator, array $contentIds): void
    {
        $sync = collect($contentIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0)
            ->unique()
            ->mapWithKeys(fn (int $id): array => [$id => ['role' => 'owner', 'is_primary' => false]])
            ->all();

        $creator->contents()->sync($sync);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'platform_fee_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'is_active' => ['sometimes', 'boolean'],
            'content_ids' => ['nullable', 'array'],
            'content_ids.*' => ['integer'],
        ]);
    }
}
