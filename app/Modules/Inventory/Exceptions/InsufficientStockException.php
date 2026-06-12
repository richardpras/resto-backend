<?php

namespace App\Modules\Inventory\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InsufficientStockException extends Exception
{
    /** @param list<array<string, mixed>> $stockErrors */
    public function __construct(
        public readonly array $stockErrors,
        public readonly ?int $orderId = null,
        public readonly ?string $orderCode = null,
        string $message = 'Some items are out of stock.',
    ) {
        parent::__construct($message);
    }

    public function render(Request $request): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'code' => 'INSUFFICIENT_STOCK',
            'errors' => [
                'stock' => $this->stockErrors,
            ],
            'recoverable' => true,
            'orderId' => $this->orderId,
            'orderCode' => $this->orderCode,
        ], 422);
    }
}
