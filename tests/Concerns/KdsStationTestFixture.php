<?php

namespace Tests\Concerns;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Production\Domain\ProductionStation;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Kitchen\Services\KitchenTicketService;
use App\Modules\Production\Services\ProductionStationProvisioner;
use Illuminate\Support\Facades\DB;

trait KdsStationTestFixture
{
    /** @return array<string, ProductionStation> */
    protected function provisionDefaultStations(Outlet $outlet): array
    {
        $stations = app(ProductionStationProvisioner::class)->ensureForOutlet($outlet, null, 1);

        $indexed = [];
        foreach ($stations as $station) {
            $indexed[(string) $station->code] = $station;
        }

        return $indexed;
    }

    protected function createMenuItem(
        Outlet $outlet,
        string $name,
        ProductionStation $station,
        string $category = 'Food',
    ): MenuItem {
        return MenuItem::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => $name,
            'category' => $category,
            'production_station_id' => $station->id,
            'price' => 10000,
            'available' => true,
        ]);
    }

    /**
     * @param  list<array{menuItem: MenuItem, qty?: float, price?: float}>  $lines
     */
    protected function createConfirmedOrderWithMenuItems(Outlet $outlet, string $code, array $lines): int
    {
        $items = [];
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $qty = (float) ($line['qty'] ?? 1);
            $price = (float) ($line['price'] ?? (float) $line['menuItem']->price);
            $subtotal += $qty * $price;
            $items[] = [
                'id' => (string) $line['menuItem']->id,
                'name' => (string) $line['menuItem']->name,
                'qty' => $qty,
                'price' => $price,
            ];
        }

        $response = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outlet->id,
            'code' => $code,
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'serviceMode' => 'takeaway',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => $items,
            'subtotal' => $subtotal,
            'tax' => 0,
            'total' => $subtotal,
            'payments' => [],
        ]);
        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    protected function resyncKitchenTickets(int $orderId): void
    {
        $order = \App\Models\Modules\Orders\Domain\Order::query()->with('items')->findOrFail($orderId);
        app(KitchenTicketService::class)->syncTicketItemsFromOrder($order);
    }

    protected function seedLegacyKitchenTicket(Outlet $outlet, int $orderId): int
    {
        $itemId = (int) DB::table('order_items')->insertGetId([
            'order_id' => $orderId,
            'item_id' => 'legacy-item',
            'name' => 'Legacy Item',
            'qty' => 1,
            'price' => 10000,
            'line_total' => 10000,
            'notes' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $ticketId = (int) DB::table('kitchen_tickets')->insertGetId([
            'outlet_id' => $outlet->id,
            'order_id' => $orderId,
            'ticket_no' => 'KDS-'.$outlet->id.'-'.$orderId,
            'status' => 'queued',
            'queued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('kitchen_ticket_items')->insert([
            'kitchen_ticket_id' => $ticketId,
            'order_item_id' => $itemId,
            'item_name_snapshot' => 'Legacy Item',
            'qty' => 1,
            'status' => 'queued',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $ticketId;
    }
}
