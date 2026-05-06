<?php

namespace App\Modules\Orders\DTOs;

readonly class CreateOrderData
{
    public function __construct(
        public ?int $tenantId,
        public ?int $outletId,
        public string $code,
        public string $source,
        public string $orderType,
        public string $status,
        public string $paymentStatus,
        public array $items,
        public array $payments,
        public float $subtotal,
        public float $tax,
        public float $total,
        public float $discountAmount = 0,
        public ?string $customerName = null,
        public ?string $customerPhone = null,
        public ?int $tableId = null,
        public ?string $tableNumber = null,
        public ?string $createdAt = null,
        public ?string $confirmedAt = null,
        public ?array $splitBill = null,
    ) {}

    public static function fromArray(array $payload): self
    {
        return new self(
            tenantId: isset($payload['tenantId']) ? (int) $payload['tenantId'] : null,
            outletId: isset($payload['outletId']) ? (int) $payload['outletId'] : null,
            code: (string) $payload['code'],
            source: (string) $payload['source'],
            orderType: (string) $payload['orderType'],
            status: (string) $payload['status'],
            paymentStatus: (string) $payload['paymentStatus'],
            items: $payload['items'] ?? [],
            payments: $payload['payments'] ?? [],
            subtotal: (float) $payload['subtotal'],
            tax: (float) $payload['tax'],
            total: (float) $payload['total'],
            discountAmount: isset($payload['discountAmount']) ? (float) $payload['discountAmount'] : 0,
            customerName: $payload['customerName'] ?? null,
            customerPhone: $payload['customerPhone'] ?? null,
            tableId: isset($payload['tableId']) ? (int) $payload['tableId'] : null,
            tableNumber: $payload['tableNumber'] ?? null,
            createdAt: $payload['createdAt'] ?? null,
            confirmedAt: $payload['confirmedAt'] ?? null,
            splitBill: $payload['splitBill'] ?? null,
        );
    }
}
