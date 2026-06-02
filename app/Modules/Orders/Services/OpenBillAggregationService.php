<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Validation\ValidationException;

class OpenBillAggregationService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * Open bill is a read model projection of existing orders.
     *
     * @return array{
     *   table: array{id:int,outletId:int,name:string,code:string|null},
     *   orders: array<int,array<string,mixed>>,
     *   subtotal: float,
     *   tax: float,
     *   service: float,
     *   remainingPayable: float,
     *   orderCount: int
     * }
     */
    public function aggregateByTable(User $user, int $outletId, int $tableId): array
    {
        $this->assertOutletAllowed($user, $outletId);

        $table = RestaurantTable::query()
            ->where('outlet_id', $outletId)
            ->whereKey($tableId)
            ->first();
        if ($table === null) {
            throw ValidationException::withMessages([
                'tableId' => ['Table not found for this outlet.'],
            ]);
        }

        $orders = Order::query()
            ->where('outlet_id', $outletId)
            ->where('table_id', $tableId)
            ->whereIn('payment_status', ['unpaid', 'partial'])
            ->where('status', '!=', 'cancelled')
            ->with(['payments:id,order_id,amount,status'])
            ->orderBy('created_at')
            ->get();

        $items = $orders->map(fn (Order $order): array => $this->mapOrderSummary($order))->values()->all();
        $subtotal = (float) $orders->sum('subtotal');
        $tax = (float) $orders->sum('tax');
        $service = 0.0; // Reserved for future explicit service-charge field.
        $remainingPayable = (float) collect($items)->sum(fn (array $row): float => (float) $row['remainingPayable']);

        return [
            'table' => [
                'id' => (int) $table->id,
                'outletId' => (int) $table->outlet_id,
                'name' => (string) $table->name,
                'code' => $table->code !== null ? (string) $table->code : null,
            ],
            'orders' => $items,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'service' => $service,
            'remainingPayable' => $remainingPayable,
            'orderCount' => count($items),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function mapOrderSummary(Order $order): array
    {
        $settledAmount = (float) $order->payments
            ->filter(fn ($payment): bool => (string) ($payment->status ?? 'paid') !== 'void')
            ->sum('amount');
        $remaining = max(0.0, (float) $order->total - $settledAmount);

        return [
            'id' => (int) $order->id,
            'code' => (string) $order->code,
            'source' => (string) $order->source,
            'orderChannel' => $order->order_channel,
            'status' => (string) $order->status,
            'paymentStatus' => (string) $order->payment_status,
            'customerName' => $order->customer_name,
            'subtotal' => (float) $order->subtotal,
            'tax' => (float) $order->tax,
            'service' => 0.0,
            'total' => (float) $order->total,
            'settledAmount' => $settledAmount,
            'remainingPayable' => $remaining,
            'createdAt' => $order->created_at?->toISOString(),
        ];
    }

    private function assertOutletAllowed(User $user, int $outletId): void
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outletId is invalid.'],
            ]);
        }
    }
}
