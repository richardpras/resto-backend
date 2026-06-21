<?php

namespace App\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QrOrderPendingSummaryEntryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'requestCode' => (string) $this->request_code,
            'outletId' => (int) $this->outlet_id,
            'tableId' => (int) $this->table_id,
            'tableName' => $this->table?->name,
            'customerName' => $this->customer_name,
            'cashierCallCount' => (int) ($this->cashier_call_count ?? 0),
            'cashierCalledAt' => $this->cashier_called_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }
}
