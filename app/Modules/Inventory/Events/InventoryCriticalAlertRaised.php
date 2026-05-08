<?php

namespace App\Modules\Inventory\Events;

use App\Events\Realtime\OutletRealtimeEvent;

class InventoryCriticalAlertRaised extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly int $ingredientId,
        private readonly string $ingredientName,
        private readonly float $currentStock,
        private readonly float $minimumStock,
        private readonly ?int $movementId = null,
        private readonly ?int $sequence = null,
        private readonly ?string $aggregateUpdatedAtIso = null,
        ?string $correlationId = null,
    ) {
        parent::__construct($outletId, 1, $correlationId);
    }

    protected function eventName(): string
    {
        return 'inventory.critical.alert.raised';
    }

    protected function aggregateType(): string
    {
        return 'ingredient_stock';
    }

    protected function aggregateId(): string
    {
        return (string) $this->ingredientId;
    }

    protected function channelSuffix(): string
    {
        return 'inventory-alerts';
    }

    protected function data(): array
    {
        return [
            'ingredient_id' => $this->ingredientId,
            'ingredient_name' => $this->ingredientName,
            'current_stock' => $this->currentStock,
            'minimum_stock' => $this->minimumStock,
            'movement_id' => $this->movementId,
            'meta' => $this->meta($this->sequence, $this->aggregateUpdatedAtIso),
        ];
    }
}
