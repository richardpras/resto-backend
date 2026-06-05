<?php

namespace App\Http\Middleware;

use App\Modules\HR\Services\EssFeatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEmployeeSelfServiceEnabled
{
    public function __construct(
        private readonly EssFeatureService $essFeature,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->essFeature->isEnabled()) {
            return response()->json([
                'message' => 'Employee self service is disabled.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
