<?php

namespace App\Modules\Inventory\DTOs;

readonly class CreateIngredientData
{
    public function __construct(
        public ?int $tenantId,
        public ?int $outletId,
        public string $name,
        public string $type,
        public string $unit,
        public float $stock,
        public float $min,
        public ?float $price = null,
        public ?string $notes = null,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            tenantId: isset($payload['tenantId']) ? (int) $payload['tenantId'] : null,
            outletId: isset($payload['outletId']) ? (int) $payload['outletId'] : null,
            name: (string) $payload['name'],
            type: (string) $payload['type'],
            unit: (string) $payload['unit'],
            stock: (float) ($payload['stock'] ?? 0),
            min: (float) ($payload['min'] ?? 0),
            price: isset($payload['price']) ? (float) $payload['price'] : null,
            notes: $payload['notes'] ?? null,
        );
    }
}
