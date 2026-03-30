<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdminPanelAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null || ! $user->hasAdminPanelAccess()) {
            return new JsonResponse([
                'message' => 'This account does not have access to the admin dashboard.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
