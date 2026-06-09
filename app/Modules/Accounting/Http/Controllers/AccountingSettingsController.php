<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\AccountingSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AccountingSettingsController extends Controller
{
    public function __construct(
        private readonly AccountingSettingsService $accountingSettingsService,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $tenantId = $request->query('tenantId') !== null ? (int) $request->query('tenantId') : null;
        $outletId = $request->query('outletId') !== null ? (int) $request->query('outletId') : null;

        return response()->json([
            'data' => $this->accountingSettingsService->get($tenantId, $outletId),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenantId' => ['nullable', 'integer'],
            'outletId' => ['nullable', 'integer'],
            'revenuePostingMode' => ['required', Rule::in(['realtime', 'shift_close'])],
        ]);

        $data = $this->accountingSettingsService->update(
            $request->user('api'),
            isset($validated['tenantId']) ? (int) $validated['tenantId'] : null,
            isset($validated['outletId']) ? (int) $validated['outletId'] : null,
            $validated,
        );

        return response()->json([
            'message' => 'Accounting settings updated.',
            'data' => $data,
        ]);
    }
}
