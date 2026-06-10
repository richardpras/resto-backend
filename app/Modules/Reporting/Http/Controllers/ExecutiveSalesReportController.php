<?php

namespace App\Modules\Reporting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Reporting\Services\ExecutiveSalesReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExecutiveSalesReportController extends Controller
{
    public function __construct(
        private readonly ExecutiveSalesReportService $executiveSalesReportService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'outletId' => ['nullable', 'integer', 'min:1'],
            'startDate' => ['nullable', 'date_format:Y-m-d'],
            'endDate' => ['nullable', 'date_format:Y-m-d'],
            'comparisonPeriod' => ['nullable', 'string', 'in:previous_period'],
        ]);

        $user = $request->user('api');
        abort_unless($user instanceof \App\Models\User, 401);

        $includeAccounting = $user->hasPermission('accounting.manage');

        return response()->json([
            'data' => $this->executiveSalesReportService->report(
                $user,
                $validated,
                $includeAccounting,
            ),
        ]);
    }
}
