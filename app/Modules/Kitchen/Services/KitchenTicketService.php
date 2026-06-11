<?php

namespace App\Modules\Kitchen\Services;

use App\Models\Modules\Kitchen\Domain\KitchenTicket;
use App\Models\Modules\Kitchen\Domain\KitchenTicketItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Production\Domain\ProductionStation;
use App\Models\User;
use App\Modules\Kitchen\Events\KitchenTicketTransitioned;
use App\Modules\Kitchen\Repositories\KitchenTicketRepositoryInterface;
use App\Modules\Kitchen\Support\KitchenRealtimeSnapshot;
use App\Modules\Orders\Services\PosAuditLogService;
use App\Modules\Orders\Services\PosIdempotencyService;
use App\Modules\Orders\Services\PosTransitionValidator;
use App\Modules\Settings\Support\OutletAccessResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class KitchenTicketService
{
    public function __construct(
        private readonly KitchenTicketRepositoryInterface $ticketRepository,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly PosTransitionValidator $transitionValidator,
        private readonly PosIdempotencyService $idempotencyService,
        private readonly PosAuditLogService $auditLogService,
        private readonly KdsStationResolver $stationResolver,
    ) {}

    /** @param array<string,mixed> $filters */
    public function listTickets(User $user, array $filters = []): LengthAwarePaginator
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $requestedOutletId = isset($filters['outlet_id']) ? (int) $filters['outlet_id'] : null;
        if ($requestedOutletId !== null && $requestedOutletId > 0 && ! in_array($requestedOutletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outletId is invalid.'],
            ]);
        }

        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : 20;

        return $this->ticketRepository->paginateByOutletScope($perPage, $allowed, $filters);
    }

    public function updateStatus(User $user, int $ticketId, string $status, ?string $idempotencyKey = null, ?string $expectedUpdatedAt = null): ?KitchenTicket
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);

        return DB::transaction(function () use ($allowed, $ticketId, $status, $idempotencyKey, $expectedUpdatedAt, $user): ?KitchenTicket {
            return $this->idempotencyService->run(
                'kitchen.ticket.status.'.$ticketId,
                $idempotencyKey,
                ['status' => $status, 'expectedUpdatedAt' => $expectedUpdatedAt],
                function () use ($allowed, $ticketId, $status, $expectedUpdatedAt, $user): ?KitchenTicket {
                    $ticket = KitchenTicket::query()
                        ->whereIn('outlet_id', $allowed === [] ? [-1] : $allowed)
                        ->whereKey($ticketId)
                        ->lockForUpdate()
                        ->with('items')
                        ->first();

                    if ($ticket === null) {
                        return null;
                    }
                    if (is_string($expectedUpdatedAt) && trim($expectedUpdatedAt) !== '') {
                        $expected = CarbonImmutable::parse($expectedUpdatedAt)->utc();
                        $actual = $ticket->updated_at?->copy()?->utc();
                        if ($actual === null || ! $actual->equalTo($expected)) {
                            throw ValidationException::withMessages([
                                'expectedUpdatedAt' => ['Resource was modified by another request. Refresh and retry.'],
                            ]);
                        }
                    }

                    $this->transitionValidator->assertKitchenStatusTransition((string) $ticket->status, $status);
                    $attributes = ['status' => $status];

                    if ($status === 'in_progress' && $ticket->started_at === null) {
                        $attributes['started_at'] = now();
                    }
                    if ($status === 'ready' && $ticket->ready_at === null) {
                        $attributes['ready_at'] = now();
                    }
                    if ($status === 'served' && $ticket->served_at === null) {
                        $attributes['served_at'] = now();
                    }

                    $this->ticketRepository->update($ticket, $attributes);
                    KitchenTicketItem::query()
                        ->where('kitchen_ticket_id', $ticket->id)
                        ->update(['status' => $status]);
                    Order::query()->whereKey($ticket->order_id)->update(['kitchen_status' => $status]);
                    $this->auditLogService->log(
                        'kitchen.ticket.status.updated',
                        'kitchen_ticket',
                        (int) $ticket->id,
                        (int) $ticket->outlet_id,
                        $user,
                        ['status' => $status]
                    );
                    $fresh = $this->ticketRepository->findScoped((int) $ticket->id, $allowed);
                    if ($fresh !== null) {
                        $this->publishTicketTransitioned($fresh);
                    }

                    return $fresh;
                }
            );
        });
    }

    public function createFromOrder(Order $order): ?KitchenTicket
    {
        if (! in_array((string) $order->status, ['confirmed', 'completed'], true)) {
            return null;
        }

        $tickets = $this->syncAllStationTicketsFromOrder($order);

        return $tickets[0] ?? null;
    }

    public function syncTicketItemsFromOrder(Order $order): ?KitchenTicket
    {
        if (! in_array((string) $order->status, ['confirmed', 'completed'], true)) {
            return null;
        }

        $tickets = $this->syncAllStationTicketsFromOrder($order);

        return $tickets[0] ?? null;
    }

    /**
     * @return list<KitchenTicket>
     */
    private function syncAllStationTicketsFromOrder(Order $order): array
    {
        return DB::transaction(function () use ($order): array {
            $order->loadMissing('items');
            $orderItems = $order->relationLoaded('items') ? $order->items : $order->items()->get();
            $outletId = (int) $order->outlet_id;

            KitchenTicket::query()->where('order_id', $order->id)->lockForUpdate()->get();

            $grouped = $this->stationResolver->groupOrderItemsByStation($orderItems, $outletId);
            $groups = $grouped['groups'];

            if ($groups === []) {
                $this->removeAllTicketsForOrder((int) $order->id);

                return [];
            }

            $tickets = [];
            $activeStationIds = [];
            $hasStationTickets = false;

            foreach ($groups as $group) {
                /** @var ?ProductionStation $station */
                $station = $group['station'];
                /** @var Collection<int, OrderItem> $items */
                $items = $group['items'];
                if ($items->isEmpty()) {
                    continue;
                }

                if ($station !== null) {
                    $hasStationTickets = true;
                    $activeStationIds[] = (int) $station->id;
                }

                $ticket = $this->upsertStationTicket($order, $station, $items);
                $tickets[] = $ticket;
            }

            $this->pruneObsoleteTickets((int) $order->id, $activeStationIds, $hasStationTickets);

            if ($tickets !== []) {
                Order::query()->whereKey($order->id)->update(['kitchen_status' => 'queued']);
            }

            foreach ($tickets as $ticket) {
                $loaded = $ticket->fresh(['items.orderItem', 'order', 'productionStation']);
                if ($loaded !== null) {
                    $this->publishTicketTransitioned($loaded);
                }
            }

            return array_values(array_filter(
                array_map(fn (KitchenTicket $ticket): ?KitchenTicket => $ticket->fresh(['items.orderItem', 'order', 'productionStation']), $tickets)
            ));
        });
    }

    /**
     * @param  Collection<int, OrderItem>  $orderItems
     */
    private function upsertStationTicket(Order $order, ?ProductionStation $station, Collection $orderItems): KitchenTicket
    {
        $outletId = (int) $order->outlet_id;
        $orderId = (int) $order->id;
        $stationId = $station?->id !== null ? (int) $station->id : null;

        $ticket = KitchenTicket::query()
            ->where('order_id', $orderId)
            ->when(
                $stationId !== null,
                fn ($query) => $query->where('production_station_id', $stationId),
                fn ($query) => $query->whereNull('production_station_id'),
            )
            ->first();

        $ticketAttributes = [
            'outlet_id' => $outletId,
            'order_id' => $orderId,
            'production_station_id' => $stationId,
            'station_code' => $station?->code,
            'station_name' => $station?->name,
            'ticket_no' => $this->generateTicketNo($outletId, $orderId, $station),
        ];

        if ($ticket === null) {
            $ticket = $this->ticketRepository->create(array_merge($ticketAttributes, [
                'status' => 'queued',
                'queued_at' => now(),
            ]));
            $this->auditLogService->log(
                'kitchen.ticket.created',
                'kitchen_ticket',
                (int) $ticket->id,
                $outletId,
                null,
                ['orderId' => $orderId, 'stationCode' => $station?->code]
            );
        } else {
            $this->ticketRepository->update($ticket, $ticketAttributes);
        }

        $this->syncItemsForStationTicket($ticket, $orderItems, $station);

        return $ticket;
    }

    /**
     * @param  Collection<int, OrderItem>  $orderItems
     */
    private function syncItemsForStationTicket(KitchenTicket $ticket, Collection $orderItems, ?ProductionStation $station): void
    {
        $orderItemIds = $orderItems->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        $ticketStatus = (string) $ticket->status;

        if ($orderItemIds === []) {
            KitchenTicketItem::query()->where('kitchen_ticket_id', $ticket->id)->delete();

            return;
        }

        KitchenTicketItem::query()
            ->where('kitchen_ticket_id', $ticket->id)
            ->whereNotIn('order_item_id', $orderItemIds)
            ->delete();

        foreach ($orderItems as $item) {
            $existing = KitchenTicketItem::query()
                ->where('kitchen_ticket_id', $ticket->id)
                ->where('order_item_id', $item->id)
                ->first();

            $itemAttributes = [
                'item_name_snapshot' => (string) $item->name,
                'qty' => (float) $item->qty,
                'notes' => $item->notes,
                'production_station_id' => $station?->id,
                'station_code' => $station?->code,
                'station_name' => $station?->name,
            ];

            if ($existing !== null) {
                $existing->update($itemAttributes);

                continue;
            }

            KitchenTicketItem::query()->create(array_merge($itemAttributes, [
                'kitchen_ticket_id' => $ticket->id,
                'order_item_id' => (int) $item->id,
                'status' => $ticketStatus,
            ]));
        }
    }

    /**
     * @param  list<int>  $activeStationIds
     */
    private function pruneObsoleteTickets(int $orderId, array $activeStationIds, bool $hasStationTickets): void
    {
        $query = KitchenTicket::query()->where('order_id', $orderId);

        if ($hasStationTickets) {
            $query->where(function ($builder) use ($activeStationIds): void {
                $builder->whereNull('production_station_id');
                if ($activeStationIds !== []) {
                    $builder->orWhereNotIn('production_station_id', $activeStationIds);
                }
            });
        } else {
            $query->whereNotNull('production_station_id');
        }

        $query->delete();
    }

    private function removeAllTicketsForOrder(int $orderId): void
    {
        KitchenTicket::query()->where('order_id', $orderId)->delete();
    }

    private function publishTicketTransitioned(KitchenTicket $ticket): void
    {
        event(new KitchenTicketTransitioned(
            outletId: (int) $ticket->outlet_id,
            snapshot: KitchenRealtimeSnapshot::fromModel($ticket),
        ));
    }

    private function generateTicketNo(int $outletId, int $orderId, ?ProductionStation $station): string
    {
        if ($station !== null && $station->code !== null && $station->code !== '') {
            return sprintf('KDS-%d-%d-%s', $outletId, $orderId, strtolower((string) $station->code));
        }

        return sprintf('KDS-%d-%d', $outletId, $orderId);
    }
}
