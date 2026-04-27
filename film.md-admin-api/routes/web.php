<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name', 'filmoteca.md API'),
        'status' => 'ok',
        'message' => 'This service is running in API-only mode.',
        'api_prefix' => '/api/v1',
        'health' => '/up',
    ]);
})->name('home');

Route::fallback(function () {
    return response()->json([
        'message' => 'Web frontend is disabled on this service. Use the API endpoints instead.',
    ], 404);
});
