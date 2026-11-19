<?php

namespace App\Http\Controllers\Api;

use App\Services\Payments\Mia\MiaPaymentService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * POST /api/signals — Victoriabank relays BNM's QR lifecycle events (Payment, Expiration,
 * Inactivation) here as a JSON string holding a JWT signed with the bank's key.
 * The bank expects 204 on success; it does not retry, so the status poller is the backup.
 */
class MiaSignalController extends ApiController
{
    public function __construct(
        protected MiaPaymentService $mia,
    ) {}

    public function __invoke(Request $request): Response
    {
        $result = $this->mia->handleSignal((string) $request->getContent());

        return response()->noContent($result['status']);
    }
}
