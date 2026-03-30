<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateApiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (! is_string($bearerToken) || trim($bearerToken) === '') {
            return new JsonResponse([
                'message' => 'Authentication token is required.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $accessToken = PersonalAccessToken::findValidToken($bearerToken);

        if ($accessToken === null || $accessToken->user === null) {
            return new JsonResponse([
                'message' => 'Authentication token is invalid or expired.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $user = $accessToken->user;
        $request->attributes->set('currentAccessToken', $accessToken);
        $request->setUserResolver(fn () => $user);
        Auth::setUser($user);

        if ($accessToken->last_used_at === null || $accessToken->last_used_at->lt(now()->subMinutes(5))) {
            $accessToken->forceFill(['last_used_at' => now()])->saveQuietly();
            $user->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $next($request);
    }
}
