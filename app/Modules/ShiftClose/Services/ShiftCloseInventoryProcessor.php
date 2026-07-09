<?php

namespace App\Modules\ShiftClose\Services;

use App\Modules\Inventory\Services\InventoryConsumptionPostingService;
use App\Modules\Inventory\Services\InventoryPostingPolicyService;

class ShiftCloseInventoryProcessor
{
    public function __construct(
        private readonly InventoryConsumptionPostingService $inventoryConsumptionPostingService,
        private readonly InventoryPostingPolicyService $postingPolicyService,
    ) {}

    /** @return array<string, int|float|bool> */
    public function process(int $outletId): array
    {
        if (! $this->postingPolicyService->shouldPostInventoryAtShiftClose($outletId)) {
            return [
                'processed' => 0,
                'reviewRequired' => 0,
                'failed' => 0,
                'varianceDetected' => 0,
                'totalCogs' => 0.0,
                'skipped' => true,
            ];
        }

        $result = $this->inventoryConsumptionPostingService->processOutlet($outletId, 'shift_close');

        return [
            'processed' => (int) ($result['processed'] ?? 0),
            'reviewRequired' => (int) ($result['reviewRequired'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'varianceDetected' => (int) ($result['reviewRequired'] ?? 0),
            'totalCogs' => (float) ($result['totalCogs'] ?? 0.0),
            'skipped' => false,
        ];
    }
}
