<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Accounting\Services\CashFlowStatementService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CashFlowReportController extends Controller
{
    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly CashFlowStatementService $cashFlowStatementService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'outletId' => ['nullable', 'integer', 'min:1'],
            'tenantId' => ['nullable', 'integer'],
            'period' => ['nullable', 'in:monthly,quarterly,yearly'],
        ]);
        $user = $request->user('api');
        if ($user instanceof \App\Models\User && isset($v['outletId'])) {
            $this->accountingService->assertOutletAllowedForActor($user, (int) $v['outletId']);
        }

        return response()->json([
            'data' => $this->cashFlowStatementService->buildReport(
                $v['from'] ?? null,
                $v['to'] ?? null,
                isset($v['outletId']) ? (int) $v['outletId'] : null,
                isset($v['tenantId']) ? (int) $v['tenantId'] : null,
                $v['period'] ?? 'monthly',
            ),
        ]);
    }
}
