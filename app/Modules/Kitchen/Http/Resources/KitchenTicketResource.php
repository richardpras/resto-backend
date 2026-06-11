<?php

namespace App\Modules\Kitchen\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class KitchenTicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $order = $this->relationLoaded('order') ? $this->order : null;
        $tableNumber = null;
        if ($order !== null) {
            $tableNumber = $order->table_name !== null && $order->table_name !== ''
                ? (string) $order->table_name
                : ($order->table_number !== null ? (string) $order->table_number : null);
        }

        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'orderId' => (int) $this->order_id,
            'orderNumber' => $order !== null ? (string) $order->code : null,
            'orderCode' => $order !== null ? (string) $order->code : null,
            'tableNumber' => $tableNumber,
            'serviceMode' => $order !== null ? (string) ($order->service_mode ?? '') : null,
            'ticketNo' => (string) $this->ticket_no,
            'status' => (string) $this->status,
            'station' => $this->stationPayload(),
            'queuedAt' => $this->queued_at?->toISOString(),
            'startedAt' => $this->started_at?->toISOString(),
            'readyAt' => $this->ready_at?->toISOString(),
            'servedAt' => $this->served_at?->toISOString(),
            'items' => $this->whenLoaded('items', fn () => $this->items->map(function ($item) {
                $oi = $item->relationLoaded('orderItem') ? $item->orderItem : null;

                return [
                    'id' => (int) $item->id,
                    'orderItemId' => (int) $item->order_item_id,
                    'name' => (string) $item->item_name_snapshot,
                    'qty' => (float) $item->qty,
                    'notes' => $item->notes,
                    'status' => (string) $item->status,
                    'recoveryStatus' => $oi?->recovery_status,
                    'recoveryReason' => $oi?->recovery_reason,
                    'station' => $this->itemStationPayload($item),
                ];
            })->values()),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array{id:int,code:string,name:string}|null
     */
    private function stationPayload(): ?array
    {
        if ($this->production_station_id !== null) {
            return [
                'id' => (int) $this->production_station_id,
                'code' => (string) ($this->station_code ?? ''),
                'name' => (string) ($this->station_name ?? ''),
            ];
        }

        if (is_string($this->station_code) && $this->station_code !== '') {
            return [
                'id' => 0,
                'code' => (string) $this->station_code,
                'name' => (string) ($this->station_name ?? $this->station_code),
            ];
        }

        return null;
    }

    /**
     * @return array{id:?int,code:?string,name:?string}|null
     */
    private function itemStationPayload(mixed $item): ?array
    {
        if ($item->production_station_id !== null) {
            return [
                'id' => (int) $item->production_station_id,
                'code' => $item->station_code,
                'name' => $item->station_name,
            ];
        }

        if (is_string($item->station_code) && $item->station_code !== '') {
            return [
                'id' => null,
                'code' => $item->station_code,
                'name' => $item->station_name,
            ];
        }

        return null;
    }
}
