<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Models\PaymentRefund;
use App\Models\PaymentTopUp;
use App\Services\AuditLogService;
use App\Services\PayFilmotecaPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class PaymentTopUpController extends ApiController
{
    public function __construct(
        protected PayFilmotecaPaymentService $payments,
        protected AuditLogService $auditLog,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $search = trim((string) $request->query('search', ''));

        $topUps = PaymentTopUp::query()
            ->with(['user', 'wallet', 'refunds' => fn ($query) => $query->latest('id')])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($nested) use ($search): void {
                    if (preg_match('~^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$~i', $search) === 1) {
                        $nested->where('uuid', $search);
                    }

                    $nested
                        ->orWhere('provider_order_id', 'like', "%{$search}%")
                        ->orWhere('provider_checkout_id', 'like', "%{$search}%")
                        ->orWhere('provider_rrn', 'like', "%{$search}%")
                        ->orWhereHas('user', function ($userQuery) use ($search): void {
                            $userQuery
                                ->where('name', 'like', "%{$search}%")
                                ->orWhere('email', 'like', "%{$search}%");
                        });
                });
            })
            ->latest('id')
            ->limit(100)
            ->get();

        return response()->json([
            'items' => $topUps
                ->map(fn (PaymentTopUp $topUp): array => $this->topUpData($topUp))
                ->values(),
        ]);
    }

    public function refund(Request $request, PaymentTopUp $topUp): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'min:20'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $refund = $this->payments->refundTopUp(
                $topUp,
                (float) $data['amount'],
                (string) $data['reason'],
                $request->user(),
            );
        } catch (ValidationException $exception) {
            throw $exception;
        }

        $this->auditLog->record(
            'payment_refund.created',
            'payment_refund',
            $refund->id,
            [
                'payment_refund_uuid' => $refund->uuid,
                'payment_top_up_uuid' => $topUp->uuid,
                'provider_checkout_id' => $refund->provider_checkout_id,
                'provider_order_id' => $refund->provider_order_id,
                'amount' => $refund->amount,
                'currency' => $refund->currency,
            ],
            $request->user(),
            $request,
        );

        $topUp = $topUp->fresh(['user', 'wallet', 'refunds']);

        return response()->json([
            'refund' => $this->refundData($refund->fresh()),
            'top_up' => $this->topUpData($topUp),
        ]);
    }

    protected function topUpData(PaymentTopUp $topUp): array
    {
        $topUp->loadMissing('user', 'wallet', 'refunds');

        return [
            'id' => $topUp->uuid,
            'amount' => round((float) $topUp->amount, 2),
            'currency' => $topUp->currency,
            'status' => $topUp->status,
            'provider_status' => $topUp->provider_status,
            'provider_order_id' => $topUp->provider_order_id,
            'provider_checkout_id' => $topUp->provider_checkout_id,
            'provider_rrn' => $topUp->provider_rrn,
            'refunded_amount' => $this->payments->refundedAmount($topUp),
            'refundable_amount' => $this->payments->refundableAmount($topUp),
            'own_credit_balance' => $this->payments->ownCreditBalance($topUp->wallet),
            'credited_at' => $topUp->credited_at?->toIso8601String(),
            'created_at' => $topUp->created_at?->toIso8601String(),
            'updated_at' => $topUp->updated_at?->toIso8601String(),
            'user' => [
                'id' => $topUp->user?->id,
                'name' => $topUp->user?->name,
                'email' => $topUp->user?->email,
            ],
            'refunds' => $topUp->refunds
                ->map(fn (PaymentRefund $refund): array => $this->refundData($refund))
                ->values(),
        ];
    }

    protected function refundData(PaymentRefund $refund): array
    {
        return [
            'id' => $refund->uuid,
            'amount' => round((float) $refund->amount, 2),
            'currency' => $refund->currency,
            'reason' => $refund->reason,
            'status' => $refund->status,
            'provider_status' => $refund->provider_status,
            'provider_order_id' => $refund->provider_order_id,
            'provider_checkout_id' => $refund->provider_checkout_id,
            'provider_rrn' => $refund->provider_rrn,
            'processed_at' => $refund->processed_at?->toIso8601String(),
            'created_at' => $refund->created_at?->toIso8601String(),
        ];
    }
}
