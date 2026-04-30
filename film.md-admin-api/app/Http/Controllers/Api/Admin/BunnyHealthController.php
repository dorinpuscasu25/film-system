<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Api\ApiController;
use App\Services\BunnyHealthCheckService;
use Illuminate\Http\JsonResponse;

class BunnyHealthController extends ApiController
{
    public function __construct(
        protected BunnyHealthCheckService $health,
    ) {}

    public function show(): JsonResponse
    {
        return response()->json($this->health->run());
    }
}
