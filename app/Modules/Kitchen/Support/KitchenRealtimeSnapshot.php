<?php

namespace App\Modules\Kitchen\Support;

use App\Models\Modules\Kitchen\Domain\KitchenTicket;

readonly class KitchenRealtimeSnapshot
{
    /** @param  list<array<string, mixed>>  $items */
    public function __construct(
        public int $id,
        public int $outletId,
        public int $orderId,
        public string $ticketNo,
        public string $status,
        public ?string $orderCode,
        public ?string $tableNumber,
        public ?string $serviceMode,
        public ?string $queuedAtIso,
        public ?string $startedAtIso,
        public ?string $readyAtIso,
        public ?string $servedAtIso,
        public ?string $createdAtIso,
        public ?string $updatedAtIso,
        public array $items,
    ) {}

    public static function fromModel(KitchenTicket $ticket): self
    {
        $ticket->loadMissing(['items.orderItem', 'order']);

        $order = $ticket->order;
        $tableNumber = null;
        if ($order !== null) {
            $tableNumber = $order->table_name !== null && $order->table_name !== ''
                ? (string) $order->table_name
                : ($order->table_number !== null ? (string) $order->table_number : null);
        }

        $items = $ticket->items->map(static function ($item): array {
            $orderItem = $item->relationLoaded('orderItem') ? $item->orderItem : null;

            return [
                'id' => (int) $item->id,
                'order_item_id' => (int) $item->order_item_id,
                'orderItemId' => (int) $item->order_item_id,
                'name' => (string) $item->item_name_snapshot,
                'qty' => (float) $item->qty,
                'notes' => $item->notes,
                'status' => (string) $item->status,
                'recovery_status' => $orderItem?->recovery_status,
                'recoveryStatus' => $orderItem?->recovery_status,
                'recovery_reason' => $orderItem?->recovery_reason,
                'recoveryReason' => $orderItem?->recovery_reason,
            ];
        })->values()->all();

        return new self(
            id: (int) $ticket->id,
            outletId: (int) $ticket->outlet_id,
            orderId: (int) $ticket->order_id,
            ticketNo: (string) $ticket->ticket_no,
            status: (string) $ticket->status,
            orderCode: $order !== null ? (string) $order->code : null,
            tableNumber: $tableNumber,
            serviceMode: $order !== null ? (string) ($order->service_mode ?? '') : null,
            queuedAtIso: $ticket->queued_at?->toISOString(),
            startedAtIso: $ticket->started_at?->toISOString(),
            readyAtIso: $ticket->ready_at?->toISOString(),
            servedAtIso: $ticket->served_at?->toISOString(),
            createdAtIso: $ticket->created_at?->toISOString(),
            updatedAtIso: $ticket->updated_at?->toISOString(),
            items: $items,
        );
    }

    public function sequence(): int
    {
        if ($this->updatedAtIso !== null) {
            $parsed = strtotime($this->updatedAtIso);

            return $parsed !== false ? $parsed : $this->id;
        }

        return $this->id;
    }

    public function replayKey(): string
    {
        return 'kitchen_ticket:'.$this->id.':'.$this->status;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'ticket_id' => $this->id,
            'ticketId' => $this->id,
            'id' => $this->id,
            'outlet_id' => $this->outletId,
            'outletId' => $this->outletId,
            'order_id' => $this->orderId,
            'orderId' => $this->orderId,
            'order_code' => $this->orderCode,
            'orderCode' => $this->orderCode,
            'order_number' => $this->orderCode,
            'orderNumber' => $this->orderCode,
            'table_number' => $this->tableNumber,
            'tableNumber' => $this->tableNumber,
            'service_mode' => $this->serviceMode,
            'serviceMode' => $this->serviceMode,
            'ticket_no' => $this->ticketNo,
            'ticketNo' => $this->ticketNo,
            'status' => $this->status,
            'queued_at' => $this->queuedAtIso,
            'queuedAt' => $this->queuedAtIso,
            'started_at' => $this->startedAtIso,
            'startedAt' => $this->startedAtIso,
            'ready_at' => $this->readyAtIso,
            'readyAt' => $this->readyAtIso,
            'served_at' => $this->servedAtIso,
            'servedAt' => $this->servedAtIso,
            'created_at' => $this->createdAtIso,
            'createdAt' => $this->createdAtIso,
            'updated_at' => $this->updatedAtIso,
            'updatedAt' => $this->updatedAtIso,
            'items' => $this->items,
        ];
    }
}
