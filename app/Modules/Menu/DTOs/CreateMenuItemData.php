<?php

namespace App\Modules\Menu\DTOs;

readonly class CreateMenuItemData
{
    public function __construct(
        public ?int $tenantId,
        public ?int $outletId,
        public string $name,
        public ?int $menuCategoryId,
        public ?string $category,
        public ?string $emoji,
        public float $price,
        public bool $available,
        public ?int $productionStationId = null,
        public array $recipes = [],
        public array $menuItemOutlets = [],
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            tenantId: isset($payload['tenantId']) ? (int) $payload['tenantId'] : null,
            outletId: isset($payload['outletId']) ? (int) $payload['outletId'] : null,
            name: (string) $payload['name'],
            menuCategoryId: isset($payload['menuCategoryId']) ? (int) $payload['menuCategoryId'] : null,
            category: isset($payload['category']) ? (string) $payload['category'] : null,
            emoji: $payload['emoji'] ?? null,
            price: (float) $payload['price'],
            available: (bool) ($payload['available'] ?? true),
            productionStationId: isset($payload['productionStationId']) ? (int) $payload['productionStationId'] : null,
            recipes: $payload['recipes'] ?? [],
            menuItemOutlets: $payload['menuItemOutlets'] ?? [],
        );
    }
}
