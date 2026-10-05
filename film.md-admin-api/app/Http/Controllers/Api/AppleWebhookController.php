<?php

namespace App\Http\Controllers\Api;

use App\Services\AppleIapService;
use AppStoreServerLibrary\SignedDataVerifier\VerificationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class AppleWebhookController extends ApiController
{
    public function __construct(
        protected AppleIapService $appleIap,
    ) {}

    /**
     * App Store Server Notifications V2. Configure both the production and sandbox URLs in
     * App Store Connect → Users and Access → Integrations → In-App Purchase.
     *
     * The signed payload is the only thing that authenticates this request — there is no shared
     * webhook secret to check, Apple's JWS signature (verified in AppleIapService) is the proof
     * of authenticity, exactly like verifying a signed receipt rather than trusting a callback.
     */
    public function notifications(Request $request): JsonResponse
    {
        $signedPayload = (string) $request->input('signedPayload', '');
        if ($signedPayload === '') {
            return response()->json(['message' => 'Missing signedPayload.'], Response::HTTP_BAD_REQUEST);
        }

        try {
            $this->appleIap->handleNotification($signedPayload);
        } catch (VerificationException $exception) {
            // Not retryable — the payload itself doesn't check out. Acknowledge with 200 so Apple
            // doesn't keep retrying a signature that will never verify.
            Log::channel('payments')->warning('Apple notification failed verification', [
                'status' => $exception->getStatus()->name,
            ]);

            return response()->json(['message' => 'Verification failed.']);
        } catch (\Throwable $exception) {
            // Genuinely unexpected (DB down, etc.) — ask Apple to retry.
            Log::channel('payments')->error('Apple notification processing failed', [
                'error' => $exception->getMessage(),
            ]);

            return response()->json(['message' => 'Processing failed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }

        return response()->json(['message' => 'OK']);
    }
}
