<?php

namespace App\Modules\Menu\DTOs;

readonly class UpdateMenuItemData
{
    public function __construct(
        public ?string $name = null,
        public ?int $menuCategoryId = null,
        public bool $updateMenuCategoryId = false,
        public ?string $category = null,
        public bool $updateCategory = false,
        public ?string $emoji = null,
        public ?float $price = null,
        public ?bool $available = null,
        public ?int $productionStationId = null,
        public bool $updateProductionStationId = false,
        public ?array $recipes = null,
        public ?array $menuItemOutlets = null,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            name: isset($payload['name']) ? (string) $payload['name'] : null,
            menuCategoryId: isset($payload['menuCategoryId']) ? (int) $payload['menuCategoryId'] : null,
            updateMenuCategoryId: array_key_exists('menuCategoryId', $payload),
            category: isset($payload['category']) ? (string) $payload['category'] : null,
            updateCategory: array_key_exists('category', $payload),
            emoji: isset($payload['emoji']) ? (string) $payload['emoji'] : null,
            price: isset($payload['price']) ? (float) $payload['price'] : null,
            available: isset($payload['available']) ? (bool) $payload['available'] : null,
            productionStationId: array_key_exists('productionStationId', $payload) && $payload['productionStationId'] !== null
                ? (int) $payload['productionStationId']
                : null,
            updateProductionStationId: array_key_exists('productionStationId', $payload),
            recipes: $payload['recipes'] ?? null,
            menuItemOutlets: $payload['menuItemOutlets'] ?? null,
        );
    }
}
