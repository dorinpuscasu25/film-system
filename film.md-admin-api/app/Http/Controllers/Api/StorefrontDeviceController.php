<?php

namespace App\Http\Controllers\Api;

use App\Models\DeviceAuthCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Called by the already-logged-in storefront user (on their phone or computer)
 * to approve a TV pairing code. This is the human side of the device flow.
 */
class StorefrontDeviceController extends ApiController
{
    /**
     * Look up a code so the web page can show "Pair this TV?" before confirming.
     */
    public function show(Request $request, string $userCode): JsonResponse
    {
        $code = DeviceAuthCode::findPendingByUserCode($userCode);

        if ($code === null) {
            return response()->json([
                'message' => 'That code is not valid. Check it and try again.',
            ], Response::HTTP_NOT_FOUND);
        }

        if ($code->isExpired() || ! $code->isPending()) {
            return response()->json([
                'message' => 'That code has expired. Request a new one on your TV.',
            ], Response::HTTP_GONE);
        }

        return response()->json([
            'user_code' => $code->user_code,
            'device_name' => $code->device_name,
            'expires_at' => $code->expires_at?->toIso8601String(),
        ]);
    }

    /**
     * Approve (link the TV to this account) or deny the pairing request.
     */
    public function authorize(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_code' => ['required', 'string', 'max:16'],
            'action' => ['nullable', 'in:approve,deny'],
        ]);

        $code = DeviceAuthCode::findPendingByUserCode($validated['user_code']);

        if ($code === null) {
            return response()->json([
                'message' => 'That code is not valid. Check it and try again.',
            ], Response::HTTP_NOT_FOUND);
        }

        if ($code->isExpired() || ! $code->isPending()) {
            return response()->json([
                'message' => 'That code has expired. Request a new one on your TV.',
            ], Response::HTTP_GONE);
        }

        if (($validated['action'] ?? 'approve') === 'deny') {
            $code->forceFill(['status' => DeviceAuthCode::STATUS_DENIED])->save();

            return response()->json(['message' => 'Pairing request denied.']);
        }

        $code->forceFill([
            'status' => DeviceAuthCode::STATUS_APPROVED,
            'user_id' => $request->user()->id,
            'approved_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Your TV is now signed in. Head back to the TV — it will continue automatically.',
        ]);
    }
}
