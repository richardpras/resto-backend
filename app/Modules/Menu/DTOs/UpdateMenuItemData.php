<?php

namespace App\Modules\Menu\DTOs;

readonly class UpdateMenuItemData
{
    public function __construct(
        public ?string $name = null,
        public ?string $category = null,
        public ?string $emoji = null,
        public ?float $price = null,
        public ?bool $available = null,
        public ?array $recipes = null,
        public ?array $menuItemOutlets = null,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            name: isset($payload['name']) ? (string) $payload['name'] : null,
            category: isset($payload['category']) ? (string) $payload['category'] : null,
            emoji: isset($payload['emoji']) ? (string) $payload['emoji'] : null,
            price: isset($payload['price']) ? (float) $payload['price'] : null,
            available: isset($payload['available']) ? (bool) $payload['available'] : null,
            recipes: $payload['recipes'] ?? null,
            menuItemOutlets: $payload['menuItemOutlets'] ?? null,
        );
    }
}
