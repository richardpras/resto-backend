<?php

namespace App\Modules\Members\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'orderId' => (string) $this->order_id,
            'totalAmount' => (float) $this->total_amount,
            'transactionAt' => $this->transaction_at?->toIso8601String(),
        ];
    }
}
