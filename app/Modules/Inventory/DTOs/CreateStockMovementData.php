<?php

namespace App\Modules\Inventory\DTOs;

readonly class CreateStockMovementData
{
    public function __construct(
        public int $inventoryItemId,
        public string $type,
        public float $quantity,
        public string $sourceType,
        public ?string $sourceId = null,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            inventoryItemId: (int) $payload['inventory_item_id'],
            type: (string) $payload['type'],
            quantity: (float) $payload['quantity'],
            sourceType: (string) $payload['source_type'],
            sourceId: $payload['source_id'] ?? null,
        );
    }
}
