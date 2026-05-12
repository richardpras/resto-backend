<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Orders\Domain\OrderItemRecoveryEvent;
use App\Models\User;
use App\Modules\Orders\Events\OrderLifecycleChanged;
use App\Modules\Orders\Repositories\OrderRepositoryInterface;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderItemRecoveryService
{
    /** @var list<string> */
    public const STATUSES = [
        'unavailable',
        'rejected',
        'replaced',
        'refunded',
        'recovery_pending',
        'recovery_approved',
    ];

    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly PosAuditLogService $auditLogService,
    ) {}

    /**
     * @return Collection<int, OrderItemRecoveryEvent>
     */
    public function listEventsForOrder(?User $user, int $orderId): Collection
    {
        $order = $this->findOrderScoped($user, $orderId);

        return OrderItemRecoveryEvent::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id')
            ->limit(200)
            ->get();
    }

    /**
     * Kitchen / cashier operational report. Does not mutate payments or inventory.
     */
    public function reportIssue(User $actor, int $orderId, int $orderItemId, string $targetStatus, ?string $reason): OrderItem
    {
        $order = $this->findOrderScoped($actor, $orderId);
        $item = $this->findOrderItem($order, $orderItemId);

        $normalized = $this->normalizeReportStatus($actor, $targetStatus, $reason);

        return DB::transaction(function () use ($actor, $order, $item, $normalized, $reason): OrderItem {
            $previous = $item->recovery_status;
            $item->recovery_status = $normalized;
            $item->recovery_reason = $reason;
            $item->save();

            $this->recordEvent($order, $item, 'recovery_reported', $normalized, $reason, $actor, null, [
                'previous' => $previous,
            ]);

            $this->auditLogService->log(
                'order_item.recovery.reported',
                'order_item',
                (int) $item->id,
                (int) ($order->outlet_id ?? 0),
                $actor,
                ['orderId' => (int) $order->id, 'status' => $normalized, 'reason' => $reason]
            );

            $this->broadcastOrder($order->fresh());

            return $item->fresh();
        });
    }

    /**
     * Manager approval / resolution. Payment/refund side-effects are NOT executed here (audit + state only).
     */
    public function approveResolution(
        User $manager,
        int $orderId,
        int $orderItemId,
        string $resolution,
        ?string $notes,
        ?array $payload = null,
    ): OrderItem {
        $order = $this->findOrderScoped($manager, $orderId);
        $item = $this->findOrderItem($order, $orderItemId);

        return DB::transaction(function () use ($manager, $order, $item, $resolution, $notes, $payload): OrderItem {
            $resolution = strtolower(trim($resolution));
            if ($resolution === 'clear') {
                $item->recovery_status = null;
                $item->recovery_reason = null;
                $item->recovery_approved_by_user_id = null;
                $item->recovery_approved_at = null;
                $item->replaced_by_order_item_id = null;
                $item->save();
                $this->recordEvent($order, $item, 'recovery_cleared', null, $notes, $manager, $manager, $payload ?? []);
            } else {
                $next = match ($resolution) {
                    'recovery_approved' => 'recovery_approved',
                    'replaced' => 'replaced',
                    'refunded' => 'refunded',
                    default => throw ValidationException::withMessages([
                        'resolution' => ['Unsupported resolution. Use recovery_approved, replaced, refunded, or clear.'],
                    ]),
                };
                $item->recovery_status = $next;
                $item->recovery_approved_by_user_id = $manager->id;
                $item->recovery_approved_at = now();
                if ($next === 'replaced' && isset($payload['replacedByOrderItemId'])) {
                    $item->replaced_by_order_item_id = (int) $payload['replacedByOrderItemId'];
                }
                if ($notes !== null && trim($notes) !== '') {
                    $item->recovery_reason = trim($notes);
                }
                $item->save();
                $this->recordEvent($order, $item, 'recovery_approved', $next, $notes, $manager, $manager, $payload ?? []);
                $this->auditLogService->log(
                    'order_item.recovery.approved',
                    'order_item',
                    (int) $item->id,
                    (int) ($order->outlet_id ?? 0),
                    $manager,
                    ['orderId' => (int) $order->id, 'resolution' => $next, 'payload' => $payload]
                );
            }

            $this->broadcastOrder($order->fresh());

            return $item->fresh();
        });
    }

    /**
     * Idempotent audit row for manager-approved recovery settlement numbers (no payment / loyalty execution).
     *
     * @param  array<string, mixed>  $financialSnapshot
     * @return array{idempotent: bool, eventId: int|null, snapshot: array<string, mixed>}
     */
    public function recordSettlementFinancialAudit(
        User $manager,
        int $orderId,
        int $orderItemId,
        string $idempotencyKey,
        array $financialSnapshot,
        ?string $notes,
    ): array {
        $order = $this->findOrderScoped($manager, $orderId);
        $item = $this->findOrderItem($order, $orderItemId);

        return DB::transaction(function () use ($manager, $order, $item, $idempotencyKey, $financialSnapshot, $notes): array {
            $existing = OrderItemRecoveryEvent::query()
                ->where('order_item_id', $item->id)
                ->where('event_code', 'recovery_settlement_recorded')
                ->where('payload->idempotencyKey', $idempotencyKey)
                ->first();
            if ($existing !== null) {
                return ['idempotent' => true, 'eventId' => (int) $existing->id, 'snapshot' => $financialSnapshot];
            }

            $payload = [
                'idempotencyKey' => $idempotencyKey,
                'snapshot' => $financialSnapshot,
                'partialRefundCapped' => (float) ($financialSnapshot['refund']['capped'] ?? 0),
                'storeCreditAmount' => (float) ($financialSnapshot['compensation']['storeCreditAmount'] ?? 0),
                'giftCardAmount' => (float) ($financialSnapshot['compensation']['giftCardAmount'] ?? 0),
                'replacementDelta' => (float) ($financialSnapshot['replacement']['delta'] ?? 0),
                'loyaltyRollbackPoints' => (int) ($financialSnapshot['loyalty']['rollbackPointsSuggested'] ?? 0),
                'loyaltyRegrantPoints' => (int) ($financialSnapshot['loyalty']['regrantPointsSuggested'] ?? 0),
            ];

            $event = OrderItemRecoveryEvent::query()->create([
                'outlet_id' => $order->outlet_id,
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'event_code' => 'recovery_settlement_recorded',
                'recovery_status' => $item->recovery_status,
                'reason' => $notes,
                'payload' => $payload,
                'actor_user_id' => $manager->id,
                'manager_user_id' => $manager->id,
            ]);

            $this->auditLogService->log(
                'order_item.recovery.settlement_recorded',
                'order_item',
                (int) $item->id,
                (int) ($order->outlet_id ?? 0),
                $manager,
                ['orderId' => (int) $order->id, 'payload' => $payload],
            );

            $this->broadcastOrder($order->fresh());

            return ['idempotent' => false, 'eventId' => (int) $event->id, 'snapshot' => $financialSnapshot];
        });
    }

    private function normalizeReportStatus(User $actor, string $targetStatus, ?string $reason): string
    {
        $t = strtolower(trim($targetStatus));
        $hasKitchen = $actor->hasPermission('kitchen.use');
        $hasPos = $actor->hasPermission('pos.use');

        if ($t === 'manual_rejection') {
            $t = 'rejected';
        }
        if ($t === 'custom_reason') {
            if ($reason === null || trim($reason) === '') {
                throw ValidationException::withMessages([
                    'reason' => ['A reason is required when using custom_reason.'],
                ]);
            }

            return 'recovery_pending';
        }

        if (! in_array($t, ['unavailable', 'rejected', 'recovery_pending', 'preparation_failed', 'sold_out', 'ingredient_unavailable', 'printer_unavailable'], true)) {
            throw ValidationException::withMessages([
                'targetStatus' => ['Invalid recovery target status.'],
            ]);
        }

        if (in_array($t, ['sold_out', 'ingredient_unavailable', 'printer_unavailable', 'preparation_failed'], true)) {
            return 'recovery_pending';
        }

        if ($t === 'unavailable' || $t === 'rejected') {
            if (! $hasKitchen && ! $hasPos) {
                throw ValidationException::withMessages([
                    'targetStatus' => ['Not allowed to set this status.'],
                ]);
            }

            return $t;
        }

        if ($t === 'recovery_pending' && ! $hasPos && ! $hasKitchen) {
            throw ValidationException::withMessages([
                'targetStatus' => ['Not allowed.'],
            ]);
        }

        return $t === 'recovery_pending' ? 'recovery_pending' : $t;
    }

    private function findOrderScoped(?User $user, int $orderId): Order
    {
        if ($user === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
        }
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $order = $this->orderRepository->findScoped($orderId, $allowed);
        if ($order === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
        }

        return $order;
    }

    private function findOrderItem(Order $order, int $orderItemId): OrderItem
    {
        $item = OrderItem::query()
            ->where('order_id', $order->id)
            ->whereKey($orderItemId)
            ->first();
        if ($item === null) {
            throw (new ModelNotFoundException)->setModel(OrderItem::class, [(string) $orderItemId]);
        }

        return $item;
    }

    private function recordEvent(
        Order $order,
        OrderItem $item,
        string $eventCode,
        ?string $recoveryStatus,
        ?string $reason,
        User $actor,
        ?User $manager,
        array $payload,
    ): void {
        OrderItemRecoveryEvent::query()->create([
            'outlet_id' => $order->outlet_id,
            'order_id' => $order->id,
            'order_item_id' => $item->id,
            'event_code' => $eventCode,
            'recovery_status' => $recoveryStatus,
            'reason' => $reason,
            'payload' => $payload === [] ? null : $payload,
            'actor_user_id' => $actor->id,
            'manager_user_id' => $manager?->id,
        ]);
    }

    private function broadcastOrder(?Order $order): void
    {
        if ($order === null) {
            return;
        }
        event(new OrderLifecycleChanged(
            outletId: (int) ($order->outlet_id ?? 0),
            orderId: (int) $order->id,
            status: (string) $order->status,
            paymentStatus: (string) $order->payment_status,
            kitchenStatus: $order->kitchen_status !== null && $order->kitchen_status !== ''
                ? (string) $order->kitchen_status
                : null,
            sequence: (int) $order->id,
            aggregateUpdatedAtIso: $order->updated_at?->toIso8601String()
        ));
    }
}
