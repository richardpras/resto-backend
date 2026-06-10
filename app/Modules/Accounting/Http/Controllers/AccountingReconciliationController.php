<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\AccountsPayableReconciliationService;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Accounting\Services\PayrollReconciliationService;
use App\Modules\Accounting\Services\ProcurementReconciliationService;
use App\Modules\GiftCards\Services\GiftCardReconciliationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AccountingReconciliationController extends Controller
{
    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly AccountsPayableReconciliationService $apReconciliationService,
        private readonly ProcurementReconciliationService $procurementReconciliationService,
        private readonly PayrollReconciliationService $payrollReconciliationService,
        private readonly GiftCardReconciliationService $giftCardReconciliationService,
    ) {}

    public function accountsPayable(Request $request): JsonResponse
    {
        $v = $request->validate([
            'outletId' => ['nullable', 'integer', 'min:1'],
        ]);
        $user = $request->user('api');
        if ($user instanceof \App\Models\User && isset($v['outletId'])) {
            $this->accountingService->assertOutletAllowedForActor($user, (int) $v['outletId']);
        }

        return response()->json([
            'data' => $this->apReconciliationService->report(
                $user instanceof \App\Models\User ? $user : null,
                isset($v['outletId']) ? (int) $v['outletId'] : null,
            ),
        ]);
    }

    public function procurement(Request $request): JsonResponse
    {
        $v = $request->validate([
            'outletId' => ['nullable', 'integer', 'min:1'],
        ]);
        $user = $request->user('api');
        if ($user instanceof \App\Models\User && isset($v['outletId'])) {
            $this->accountingService->assertOutletAllowedForActor($user, (int) $v['outletId']);
        }

        return response()->json([
            'data' => $this->procurementReconciliationService->report(
                $user instanceof \App\Models\User ? $user : null,
                isset($v['outletId']) ? (int) $v['outletId'] : null,
            ),
        ]);
    }

    public function giftCards(Request $request): JsonResponse
    {
        $v = $request->validate([
            'outletId' => ['nullable', 'integer', 'min:1'],
        ]);
        $user = $request->user('api');
        if ($user instanceof \App\Models\User && isset($v['outletId'])) {
            $this->accountingService->assertOutletAllowedForActor($user, (int) $v['outletId']);
        }

        return response()->json([
            'data' => $this->giftCardReconciliationService->report(
                $user instanceof \App\Models\User ? $user : null,
                isset($v['outletId']) ? (int) $v['outletId'] : null,
            ),
        ]);
    }

    public function payroll(Request $request): JsonResponse
    {
        $v = $request->validate([
            'outletId' => ['nullable', 'integer', 'min:1'],
        ]);
        $user = $request->user('api');
        if ($user instanceof \App\Models\User && isset($v['outletId'])) {
            $this->accountingService->assertOutletAllowedForActor($user, (int) $v['outletId']);
        }

        return response()->json([
            'data' => $this->payrollReconciliationService->report(
                $user instanceof \App\Models\User ? $user : null,
                isset($v['outletId']) ? (int) $v['outletId'] : null,
            ),
        ]);
    }
}
