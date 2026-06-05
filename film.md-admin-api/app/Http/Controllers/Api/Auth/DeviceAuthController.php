<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Api\ApiController;
use App\Models\DeviceAuthCode;
use App\Models\PersonalAccessToken;
use App\Services\AccountProfileService;
use App\Services\WalletService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * OAuth 2.0 Device Authorization Grant (RFC 8628) — the "log in with a code"
 * flow used by Netflix / YouTube / Spotify on TVs.
 *
 *   1. TV  → POST /auth/device/code        (gets user_code + device_code)
 *   2. TV shows the user_code on screen and starts polling
 *   3. User on phone/PC (already logged in) → POST /storefront/device/authorize { user_code }
 *   4. TV  → POST /auth/device/token        (polls; once approved, gets a real token)
 */
class DeviceAuthController extends ApiController
{
    /** Seconds the pairing code stays valid. */
    private const CODE_TTL_SECONDS = 600;

    /** Recommended polling interval handed back to the TV. */
    private const POLL_INTERVAL_SECONDS = 5;

    public function __construct(
        protected AccountProfileService $profiles,
        protected WalletService $wallets,
    ) {
    }

    /**
     * Step 1 — the TV asks for a fresh pairing code.
     */
    public function requestCode(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_name' => ['nullable', 'string', 'max:120'],
        ]);

        [$code, $deviceCode] = DeviceAuthCode::issue(
            self::CODE_TTL_SECONDS,
            $validated['device_name'] ?? 'Android TV',
            $request->ip(),
        );

        $clientUrl = rtrim((string) config('services.frontend.client_url'), '/');

        return response()->json([
            'device_code' => $deviceCode,
            'user_code' => $code->user_code,
            'verification_uri' => "{$clientUrl}/tv",
            'verification_uri_complete' => "{$clientUrl}/tv?code=".rawurlencode($code->user_code),
            'expires_in' => self::CODE_TTL_SECONDS,
            'interval' => self::POLL_INTERVAL_SECONDS,
        ], Response::HTTP_OK);
    }

    /**
     * Step 4 — the TV polls until the user approves (or the code expires).
     *
     * Mirrors RFC 8628 §3.5 error codes so a well-behaved client can react:
     *   authorization_pending | slow_down | expired_token | access_denied
     */
    public function pollToken(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'device_code' => ['required', 'string'],
        ]);

        $code = DeviceAuthCode::findByDeviceCode($validated['device_code']);

        if ($code === null) {
            return $this->deviceError('invalid_grant', 'Unknown or already-used device code.', Response::HTTP_BAD_REQUEST);
        }

        if ($code->isExpired() && $code->status !== DeviceAuthCode::STATUS_CLAIMED) {
            $code->forceFill(['status' => DeviceAuthCode::STATUS_EXPIRED])->save();

            return $this->deviceError('expired_token', 'The pairing code expired. Request a new one on the TV.', Response::HTTP_GONE);
        }

        if ($code->status === DeviceAuthCode::STATUS_DENIED) {
            return $this->deviceError('access_denied', 'The request was denied.', Response::HTTP_FORBIDDEN);
        }

        // Rate-limit aggressive pollers, RFC 8628 style.
        if ($code->isPending()) {
            $tooSoon = $code->last_polled_at !== null
                && $code->last_polled_at->gt(now()->subSeconds(self::POLL_INTERVAL_SECONDS - 1));

            $code->forceFill(['last_polled_at' => now()])->save();

            if ($tooSoon) {
                return $this->deviceError('slow_down', 'Polling too frequently.', Response::HTTP_TOO_MANY_REQUESTS);
            }

            return $this->deviceError('authorization_pending', 'Waiting for the user to approve on their phone or computer.', Response::HTTP_ACCEPTED);
        }

        if (! $code->isApproved() || $code->user === null) {
            return $this->deviceError('authorization_pending', 'Waiting for approval.', Response::HTTP_ACCEPTED);
        }

        // Approved — exchange for a real access token, exactly once.
        $user = $code->user;
        $code->forceFill(['status' => DeviceAuthCode::STATUS_CLAIMED])->save();

        $this->wallets->ensureWallet($user);
        $this->profiles->ensureDefaultProfile($user);
        $user->loadMissing('roles.permissions', 'wallet', 'profiles.favorites');

        [, $plainTextToken] = PersonalAccessToken::issue($user, 'tv-device');

        return response()->json([
            'token' => $plainTextToken,
            'user' => $this->userData($user),
        ], Response::HTTP_OK);
    }

    private function deviceError(string $error, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => $error,
            'message' => $message,
        ], $status);
    }
}
