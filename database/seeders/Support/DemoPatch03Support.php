<?php

namespace Database\Seeders\Support;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderItem;
use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\Modules\Orders\Domain\QrOrderRequestItem;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoSeederContext;

final class DemoPatch03Support
{
    public static function baseTime(): CarbonImmutable
    {
        return DemoSeederContext::baseTime();
    }

    public static function outletByCode(string $code): Outlet
    {
        return Outlet::query()->where('code', $code)->firstOrFail();
    }

    public static function tableForOutlet(Outlet $outlet, string $tableCode = 'A01'): RestaurantTable
    {
        return RestaurantTable::query()
            ->where('outlet_id', $outlet->id)
            ->where('code', $tableCode)
            ->firstOrFail();
    }

    public static function menuItemByName(Outlet $outlet, string $name): MenuItem
    {
        return MenuItem::query()
            ->where('outlet_id', $outlet->id)
            ->where('name', $name)
            ->firstOrFail();
    }

    public static function cashierForOutlet(Outlet $outlet): ?User
    {
        $key = DemoSeederContext::outletKeyFor($outlet);
        if ($key === null) {
            return null;
        }
        $domain = DemoSeederContext::specForKey($key)['domain'];

        return User::query()->where('email', 'cashier.morning@'.$domain)->first();
    }

    public static function openPosSession(Outlet $outlet): PosSession
    {
        $cashier = self::cashierForOutlet($outlet);

        return PosSession::query()->updateOrCreate(
            [
                'outlet_id' => $outlet->id,
                'notes' => 'demo-patch03-preflight-open',
            ],
            [
                'opened_by_user_id' => $cashier?->id,
                'status' => 'open',
                'opening_cash' => 750000,
                'opened_at' => now()->subHours(6),
            ],
        );
    }

    /** @param list<array{menuItem: MenuItem, qty: float, notes?: string|null}> $lines */
    public static function upsertLinkedPosOrder(
        Outlet $outlet,
        string $posCode,
        QrOrderRequest $request,
        array $lines,
        string $paymentStatus,
        string $kitchenStatus,
        string $orderStatus = 'confirmed',
    ): Order {
        $session = self::openPosSession($outlet);
        $subtotal = collect($lines)->sum(fn (array $line): float => (float) $line['qty'] * (float) $line['menuItem']->price);
        $tax = round($subtotal * 0.11, 2);
        $total = $subtotal + $tax;

        $order = Order::query()->updateOrCreate(
            ['code' => $posCode],
            [
                'tenant_id' => null,
                'outlet_id' => $outlet->id,
                'pos_session_id' => $session->id,
                'source' => 'qr',
                'source_type' => 'qr_order',
                'source_id' => (int) $request->id,
                'source_code' => (string) $request->request_code,
                'order_channel' => 'qr',
                'service_mode' => 'dine_in',
                'order_type' => 'Dine In',
                'status' => $orderStatus,
                'payment_status' => $paymentStatus,
                'kitchen_status' => $kitchenStatus,
                'subtotal' => $subtotal,
                'tax' => $tax,
                'total' => $total,
                'table_id' => $request->table_id,
                'table_name' => $request->table?->name,
                'customer_name' => $request->customer_name,
                'confirmed_at' => self::baseTime()->addHours(2),
            ],
        );

        foreach ($lines as $index => $line) {
            OrderItem::query()->updateOrCreate(
                [
                    'order_id' => $order->id,
                    'item_id' => (int) $line['menuItem']->id,
                ],
                [
                    'name' => (string) $line['menuItem']->name,
                    'qty' => (float) $line['qty'],
                    'price' => (float) $line['menuItem']->price,
                    'line_total' => (float) $line['qty'] * (float) $line['menuItem']->price,
                    'notes' => $line['notes'] ?? null,
                ],
            );
        }

        $request->update(['order_id' => $order->id]);

        return $order->fresh(['items']);
    }

    /** @param list<array{menuItem: MenuItem, qty: float, notes?: string|null}> $lines */
    public static function syncQrItems(QrOrderRequest $request, array $lines): void
    {
        QrOrderRequestItem::query()->where('qr_order_request_id', $request->id)->delete();
        foreach ($lines as $line) {
            QrOrderRequestItem::query()->create([
                'qr_order_request_id' => $request->id,
                'menu_item_id' => (int) $line['menuItem']->id,
                'qty' => (float) $line['qty'],
                'notes' => $line['notes'] ?? null,
            ]);
        }
    }

    /** @param array<string, mixed>|null $payload */
    public static function audit(
        Outlet $outlet,
        QrOrderRequest $request,
        string $eventType,
        ?User $actor = null,
        ?array $payload = null,
    ): void {
        PosEventLog::query()->updateOrCreate(
            [
                'outlet_id' => $outlet->id,
                'entity_type' => 'qr_order_request',
                'entity_id' => (int) $request->id,
                'event_type' => $eventType,
            ],
            [
                'actor_user_id' => $actor?->id,
                'payload' => $payload,
                'occurred_at' => now(),
            ],
        );
    }

    public static function outletPrefix(Outlet $outlet): string
    {
        return match ($outlet->code) {
            'DEMO-SUNSET' => 'DEMO-SUNSET',
            'DEMO-MOUNTAIN' => 'DEMO-MOUNTAIN',
            default => (string) $outlet->code,
        };
    }
}
