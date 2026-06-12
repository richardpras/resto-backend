<?php

namespace App\Modules\ShiftClose\Services;

use App\Modules\Inventory\Services\InventoryConsumptionPostingService;

class ShiftCloseInventoryProcessor
{
    public function __construct(
        private readonly InventoryConsumptionPostingService $inventoryConsumptionPostingService,
    ) {}

    /** @return array<string, int|float> */
    public function process(int $outletId): array
    {
        $result = $this->inventoryConsumptionPostingService->processOutlet($outletId, 'shift_close');

        return [
            'processed' => (int) ($result['processed'] ?? 0),
            'reviewRequired' => (int) ($result['reviewRequired'] ?? 0),
            'failed' => (int) ($result['failed'] ?? 0),
            'varianceDetected' => (int) ($result['reviewRequired'] ?? 0),
            'totalCogs' => (float) ($result['totalCogs'] ?? 0.0),
        ];
    }
}
