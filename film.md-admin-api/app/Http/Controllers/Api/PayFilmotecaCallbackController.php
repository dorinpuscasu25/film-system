<?php

namespace App\Http\Controllers\Api;

use App\Services\PayFilmotecaPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class PayFilmotecaCallbackController extends ApiController
{
    public function __construct(
        protected PayFilmotecaPaymentService $payments,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        Log::channel('payments')->info('PayFilmoteca callback controller reached', [
            'method' => $request->method(),
            'client_ip' => $request->ip(),
            'query' => $request->query(),
            'body' => $request->all(),
            'content_type' => $request->headers->get('content-type'),
            'user_agent' => substr((string) $request->userAgent(), 0, 500),
        ]);

        $topUp = $this->payments->handleCallback($request);

        if ($topUp === null) {
            Log::channel('payments')->warning('PayFilmoteca callback controller returning not found', [
                'query' => $request->query(),
                'body' => $request->all(),
            ]);

            return response()->json([
                'message' => 'Top-up not found for callback payload.',
            ], Response::HTTP_NOT_FOUND);
        }

        Log::channel('payments')->info('PayFilmoteca callback controller returning accepted', [
            'top_up_uuid' => $topUp->uuid,
            'status' => $topUp->status,
            'provider_status' => $topUp->provider_status,
            'provider_order_id' => $topUp->provider_order_id,
            'credited_at' => $topUp->credited_at?->toIso8601String(),
        ]);

        return response()->json([
            'message' => 'Callback accepted.',
            'top_up' => $this->payments->topUpData($topUp),
        ]);
    }
}
