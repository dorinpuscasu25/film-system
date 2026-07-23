<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string ...$permissionArguments): Response
    {
        $user = $request->user();
        $permissions = collect($permissionArguments)
            ->flatMap(fn (string $argument): array => explode(',', $argument))
            ->map(fn (string $permission): string => trim($permission))
            ->filter()
            ->values();

        if ($user === null || ! $permissions->contains(fn (string $code): bool => $user->hasPermission($code))) {
            return new JsonResponse([
                'message' => 'You do not have permission to perform this action.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
