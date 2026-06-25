<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class KitchenReprintService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly PrinterRoutingService $printerRoutingService,
    ) {}

    /**
     * @param  list<int>  $orderItemIds
     * @return array{printJobIds:list<int>,groupedByStation:list<array{station:string,menuCategoryName:string,itemCount:int}>}
     */
    public function reprintItems(User $user, Order $order, array $orderItemIds): array
    {
        $this->assertOrderAccess($user, $order);

        $ids = collect($orderItemIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        if ($ids === []) {
            throw ValidationException::withMessages([
                'orderItemIds' => ['At least one order item is required.'],
            ]);
        }

        $order->loadMissing('items');
        $orderItemIdSet = $order->items->pluck('id')->map(fn ($id): int => (int) $id)->all();
        foreach ($ids as $id) {
            if (! in_array($id, $orderItemIdSet, true)) {
                throw ValidationException::withMessages([
                    'orderItemIds' => ['One or more items do not belong to this order.'],
                ]);
            }
        }

        $result = $this->printerRoutingService->queueKitchenReprintForOrderItems($order, $ids);
        $this->auditReprint($user, $order, $ids, $result['printJobIds']);

        return $result;
    }

  /**
   * @param  list<int>  $orderItemIds
   * @param  list<int>  $printJobIds
   */
    private function auditReprint(User $user, Order $order, array $orderItemIds, array $printJobIds): void
    {
        // Lightweight audit via application log; print job rows carry idempotency keys.
        \Illuminate\Support\Facades\Log::info('kitchen.reprint', [
            'order_id' => (int) $order->id,
            'outlet_id' => (int) $order->outlet_id,
            'user_id' => (int) $user->id,
            'order_item_ids' => $orderItemIds,
            'print_job_ids' => $printJobIds,
        ]);
    }

    private function assertOrderAccess(User $user, Order $order): void
    {
        $outletId = (int) ($order->outlet_id ?? 0);
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if ($outletId < 1 || ! in_array($outletId, $allowed, true)) {
            throw (new ModelNotFoundException)->setModel(Order::class, [(string) $order->id]);
        }
    }
}
