<?php

namespace App\Http\Controllers\Api;

use App\Services\Payments\CyberSource\CyberSourcePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * CyberSource webhooks (uc.orders.transactionresults, payment events). The signature is checked on
 * the raw body; the state changes only after the transaction is confirmed through the API.
 * CyberSource expects a 2xx within a few seconds.
 */
class CyberSourceWebhookController extends ApiController
{
    public function __construct(
        protected CyberSourcePaymentService $cyberSource,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $status = $this->cyberSource->handleWebhook((string) $request->getContent(), $request->headers->get('v-c-signature'));

        return response()->json(['received' => $status < 300], $status);
    }
}
