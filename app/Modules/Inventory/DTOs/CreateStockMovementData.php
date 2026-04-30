<?php

namespace App\Modules\Inventory\DTOs;

readonly class CreateStockMovementData
{
    public function __construct(
        public int $ingredientId,
        public string $movementType,
        public float $quantity,
        public string $source,
        public ?string $referenceNo = null,
        public ?string $note = null,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            ingredientId: (int) $payload['ingredient_id'],
            movementType: (string) $payload['movement_type'],
            quantity: (float) $payload['quantity'],
            source: (string) $payload['source'],
            referenceNo: $payload['reference_no'] ?? null,
            note: $payload['note'] ?? null,
        );
    }
}
