<?php

namespace App\Http\Controllers\Api;

use App\Services\PayFilmotecaPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PayFilmotecaCallbackController extends ApiController
{
    public function __construct(
        protected PayFilmotecaPaymentService $payments,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $topUp = $this->payments->handleCallback($request);

        if ($topUp === null) {
            return response()->json([
                'message' => 'Top-up not found for callback payload.',
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'message' => 'Callback accepted.',
            'top_up' => $this->payments->topUpData($topUp),
        ]);
    }
}
