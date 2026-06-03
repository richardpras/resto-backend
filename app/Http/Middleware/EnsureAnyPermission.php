<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAnyPermission
{
    public function handle(Request $request, Closure $next, string ...$permissions): mixed
    {
        $user = $request->user('api') ?? $request->user();
        if ($user === null) {
            return response()->json(['message' => 'Unauthenticated.'], Response::HTTP_UNAUTHORIZED);
        }

        foreach ($permissions as $permission) {
            if ($user->hasPermission($permission)) {
                return $next($request);
            }
        }

        return response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
    }
}
