<?php

namespace App\Modules\Kitchen\Services;

use App\Models\Modules\Kitchen\Domain\KitchenTicket;
use App\Models\Modules\Kitchen\Domain\KitchenTicketItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\User;
use App\Modules\Kitchen\Events\KitchenTicketTransitioned;
use App\Modules\Kitchen\Repositories\KitchenTicketRepositoryInterface;
use App\Modules\Orders\Services\PosAuditLogService;
use App\Modules\Orders\Services\PosIdempotencyService;
use App\Modules\Orders\Services\PosTransitionValidator;
use App\Modules\Settings\Support\OutletAccessResolver;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
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
                        event(new KitchenTicketTransitioned(
                            outletId: (int) $fresh->outlet_id,
                            ticketId: (int) $fresh->id,
                            orderId: (int) $fresh->order_id,
                            status: (string) $fresh->status,
                            sequence: (int) $fresh->id,
                            aggregateUpdatedAtIso: $fresh->updated_at?->toIso8601String()
                        ));
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

        return DB::transaction(function () use ($order): KitchenTicket {
            $existing = KitchenTicket::query()->where('order_id', $order->id)->lockForUpdate()->first();
            if ($existing !== null) {
                return $existing->load('items');
            }

            $ticket = $this->ticketRepository->create([
                'outlet_id' => (int) $order->outlet_id,
                'order_id' => (int) $order->id,
                'ticket_no' => $this->generateTicketNo((int) $order->outlet_id, (int) $order->id),
                'status' => 'queued',
                'queued_at' => now(),
            ]);

            $items = $order->relationLoaded('items') ? $order->items : $order->items()->get();
            foreach ($items as $item) {
                KitchenTicketItem::query()->create([
                    'kitchen_ticket_id' => $ticket->id,
                    'order_item_id' => $item->id,
                    'item_name_snapshot' => (string) $item->name,
                    'qty' => (float) $item->qty,
                    'notes' => $item->notes,
                    'status' => 'queued',
                ]);
            }

            Order::query()->whereKey($order->id)->update(['kitchen_status' => 'queued']);
            $this->auditLogService->log(
                'kitchen.ticket.created',
                'kitchen_ticket',
                (int) $ticket->id,
                (int) $ticket->outlet_id,
                null,
                ['orderId' => (int) $order->id]
            );
            event(new KitchenTicketTransitioned(
                outletId: (int) $ticket->outlet_id,
                ticketId: (int) $ticket->id,
                orderId: (int) $ticket->order_id,
                status: (string) $ticket->status,
                sequence: (int) $ticket->id,
                aggregateUpdatedAtIso: $ticket->updated_at?->toIso8601String()
            ));

            return $ticket->load('items');
        });
    }

    private function generateTicketNo(int $outletId, int $orderId): string
    {
        return sprintf('KDS-%d-%d', $outletId, $orderId);
    }
}
