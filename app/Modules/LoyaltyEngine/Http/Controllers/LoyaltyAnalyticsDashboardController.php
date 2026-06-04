<?php

namespace App\Modules\LoyaltyEngine\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\LoyaltyEngine\Services\LoyaltyAnalyticsDashboardService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LoyaltyAnalyticsDashboardController extends Controller
{
    public function __construct(
        private readonly LoyaltyAnalyticsDashboardService $dashboardService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['required', 'integer', 'min:1'],
            'fromDate' => ['nullable', 'date'],
            'toDate' => ['nullable', 'date', 'after_or_equal:fromDate'],
        ]);

        $toDate = isset($validated['toDate'])
            ? Carbon::parse((string) $validated['toDate'])->endOfDay()
            : now()->endOfDay();
        $fromDate = isset($validated['fromDate'])
            ? Carbon::parse((string) $validated['fromDate'])->startOfDay()
            : $toDate->copy()->subDays(29)->startOfDay();

        $dashboard = $this->dashboardService->dashboard(
            $this->resolveUser($request),
            (int) $validated['outletId'],
            $fromDate,
            $toDate,
        );

        return response()->json(['data' => $dashboard]);
    }

    private function resolveUser(Request $request): ?\App\Models\User
    {
        $user = $request->user('api') ?? $request->user();

        return $user instanceof \App\Models\User ? $user : null;
    }
}
