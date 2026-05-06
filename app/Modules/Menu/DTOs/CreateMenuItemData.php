<?php

namespace App\Modules\Menu\DTOs;

readonly class CreateMenuItemData
{
    public function __construct(
        public ?int $tenantId,
        public ?int $outletId,
        public string $name,
        public ?string $category,
        public ?string $emoji,
        public float $price,
        public bool $available,
        public array $recipes = [],
        public array $menuItemOutlets = [],
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            tenantId: isset($payload['tenantId']) ? (int) $payload['tenantId'] : null,
            outletId: isset($payload['outletId']) ? (int) $payload['outletId'] : null,
            name: (string) $payload['name'],
            category: $payload['category'] ?? null,
            emoji: $payload['emoji'] ?? null,
            price: (float) $payload['price'],
            available: (bool) ($payload['available'] ?? true),
            recipes: $payload['recipes'] ?? [],
            menuItemOutlets: $payload['menuItemOutlets'] ?? [],
        );
    }
}
