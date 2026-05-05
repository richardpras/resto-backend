<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\AccountingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReportController extends Controller
{
    public function __construct(
        private readonly AccountingService $accountingService,
    ) {}

    public function ledger(Request $request): JsonResponse
    {
        $v = $request->validate([
            'accountId' => ['required', 'string'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'outlet' => ['nullable', 'string'],
            'tenantId' => ['nullable', 'integer'],
        ]);

        $data = $this->accountingService->buildLedgerReport(
            $v['accountId'],
            $v['from'] ?? null,
            $v['to'] ?? null,
            $v['outlet'] ?? null,
            isset($v['tenantId']) ? (int) $v['tenantId'] : null,
        );

        return response()->json(['data' => $data]);
    }

    public function profitLoss(Request $request): JsonResponse
    {
        $v = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'outlet' => ['nullable', 'string'],
            'tenantId' => ['nullable', 'integer'],
        ]);

        $data = $this->accountingService->buildProfitLossReport(
            $v['from'] ?? null,
            $v['to'] ?? null,
            $v['outlet'] ?? null,
            isset($v['tenantId']) ? (int) $v['tenantId'] : null,
        );

        return response()->json(['data' => $data]);
    }

    public function balanceSheet(Request $request): JsonResponse
    {
        $v = $request->validate([
            'to' => ['nullable', 'date_format:Y-m-d'],
            'outlet' => ['nullable', 'string'],
            'tenantId' => ['nullable', 'integer'],
        ]);

        $data = $this->accountingService->buildBalanceSheetReport(
            $v['to'] ?? null,
            $v['outlet'] ?? null,
            isset($v['tenantId']) ? (int) $v['tenantId'] : null,
        );

        return response()->json(['data' => $data]);
    }
}
