<?php

namespace App\Modules\Orders\Services\RecoverySettlement;

use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\User;
use App\Modules\Orders\Repositories\OrderRepositoryInterface;
use App\Modules\Orders\Services\OrderItemRecoveryService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Financial-safe recovery settlement orchestration: preview + audit recording only.
 */
final class RecoverySettlementApplicationService
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly RecoverySettlementComposer $composer,
        private readonly OrderItemRecoveryService $recoveryService,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(User $user, int $orderId, int $orderItemId, array $input): array
    {
        $order = $this->findScopedOrder($user, $orderId);
        $line = $order->items->first(static function (OrderItem $it) use ($orderItemId): bool {
            return (int) $it->id === (int) $orderItemId;
        });
        if ($line === null) {
            throw (new ModelNotFoundException)->setModel(OrderItem::class, [(string) $orderItemId]);
        }

        try {
            return $this->composer->compose($order, $line, $input);
        } catch (\InvalidArgumentException $e) {
            throw new HttpException(422, $e->getMessage());
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{idempotent: bool, eventId: int|null, snapshot: array<string, mixed>}
     */
    public function recordAudit(User $user, int $orderId, int $orderItemId, array $input): array
    {
        $preview = $this->preview($user, $orderId, $orderItemId, $input);
        $idempotencyKey = trim((string) ($input['idempotencyKey'] ?? ''));
        if ($idempotencyKey === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'idempotencyKey' => ['idempotencyKey is required to record a settlement audit row.'],
            ]);
        }

        return $this->recoveryService->recordSettlementFinancialAudit(
            $user,
            $orderId,
            $orderItemId,
            $idempotencyKey,
            $preview,
            isset($input['notes']) ? (string) $input['notes'] : null,
        );
    }

    private function findScopedOrder(User $user, int $orderId): Order
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $order = $this->orderRepository->findScoped($orderId, $allowed);
        if ($order === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
        }

        return $order;
    }
}
