<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Services\OrderSourceLinkService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QrOrderRequestResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var OrderSourceLinkService $sourceLinkService */
        $sourceLinkService = app(OrderSourceLinkService::class);

        $estimatedTotal = $this->whenLoaded('items', function () {
            return (float) $this->items->sum(function ($item): float {
                $price = $item->relationLoaded('menuItem') && $item->menuItem !== null
                    ? (float) $item->menuItem->price
                    : 0.0;

                return (float) $item->qty * $price;
            });
        }, 0.0);

        return [
            'id' => (string) $this->id,
            'requestCode' => (string) $this->request_code,
            'outletId' => (int) $this->outlet_id,
            'tableId' => (int) $this->table_id,
            'tableName' => $this->table?->name,
            'customerName' => $this->customer_name,
            'status' => (string) $this->status,
            'statusLabel' => $this->statusLabel((string) $this->status),
            'decisionMode' => $this->decision_mode,
            'estimatedTotal' => $estimatedTotal,
            'cashierCalledAt' => $this->cashier_called_at?->toISOString(),
            'cashierCallCount' => (int) ($this->cashier_call_count ?? 0),
            'expiresAt' => $this->expires_at?->toISOString(),
            'confirmedAt' => $this->confirmed_at?->toISOString(),
            'rejectedAt' => $this->rejected_at?->toISOString(),
            'rejectionReason' => $this->rejection_reason,
            'orderId' => $this->order_id !== null ? (string) $this->order_id : null,
            'linkedOrder' => $sourceLinkService->buildLinkedOrder($this->resource),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(fn ($item) => [
                'id' => (string) $item->id,
                'menuItemId' => (int) $item->menu_item_id,
                'qty' => (float) $item->qty,
                'notes' => $item->notes,
            ])->values()),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending_cashier_confirmation' => 'Submitted',
            'under_review' => 'Under Review',
            'confirmed' => 'Confirmed',
            'paid' => 'Paid',
            'rejected' => 'Cancelled',
            'expired' => 'Expired',
            default => $status,
        };
    }
}
