<?php

namespace App\Modules\Inventory\Services;

use App\Models\User;
use App\Modules\Accounting\Services\GlBalanceService;

final class InventoryValuationReconciliationService
{
    public function __construct(
        private readonly InventoryValuationService $valuationService,
        private readonly GlBalanceService $glBalanceService,
        private readonly InventoryValuationAuditService $auditService,
    ) {}

    /** @return array<string,mixed> */
    public function report(?User $actor = null, ?int $outletId = null): array
    {
        $valuationBalance = $this->valuationService->outletValuationTotal($outletId);
        $inventoryGlBalance = $this->glBalanceService->categoryBalance('inventory', ['1300'], ['asset'], $outletId);
        $difference = round($inventoryGlBalance - $valuationBalance, 2);
        $absDiff = abs($difference);

        $status = match (true) {
            $absDiff <= 0.01 => 'balanced',
            $absDiff <= 1000 => 'variance',
            default => 'review',
        };

        if ($status !== 'balanced') {
            $this->auditService->log(
                'inventory_valuation_variance_detected',
                0,
                $outletId,
                $actor,
                [
                    'inventoryGlBalance' => $inventoryGlBalance,
                    'inventoryValuationBalance' => $valuationBalance,
                    'difference' => $difference,
                    'status' => $status,
                ],
            );
        }

        return [
            'inventoryValuationStatus' => $status,
            'inventoryGlBalance' => round($inventoryGlBalance, 2),
            'inventoryValuationBalance' => round($valuationBalance, 2),
            'difference' => $difference,
            'status' => $status,
        ];
    }
}
