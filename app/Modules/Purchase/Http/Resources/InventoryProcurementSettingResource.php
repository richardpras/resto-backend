<?php

namespace App\Modules\Purchase\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InventoryProcurementSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'inventoryItemId' => (string) $this->inventory_item_id,
            'inventoryItemName' => $this->inventoryItem?->name,
            'preferredSupplierId' => $this->preferred_supplier_id ? (string) $this->preferred_supplier_id : null,
            'preferredSupplierName' => $this->preferredSupplier?->name,
            'minimumOrderQty' => $this->minimum_order_qty !== null ? (float) $this->minimum_order_qty : null,
            'reorderQty' => $this->reorder_qty !== null ? (float) $this->reorder_qty : null,
            'leadTimeDays' => $this->lead_time_days,
            'lastPurchasePrice' => $this->last_purchase_price !== null ? (float) $this->last_purchase_price : null,
            'isActive' => (bool) $this->is_active,
            'createdAt' => optional($this->created_at)->toISOString(),
            'updatedAt' => optional($this->updated_at)->toISOString(),
        ];
    }
}
