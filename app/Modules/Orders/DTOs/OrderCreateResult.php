<?php

namespace App\Modules\Orders\DTOs;

use App\Models\Modules\Orders\Domain\Order;

final class OrderCreateResult
{
    /**
     * @param  array<string, mixed>|null  $meta
     */
    public function __construct(
        public readonly Order $order,
        public readonly ?array $meta = null,
        public readonly bool $created = true,
    ) {}

    public function httpStatus(): int
    {
        return $this->created ? 201 : 200;
    }
}
