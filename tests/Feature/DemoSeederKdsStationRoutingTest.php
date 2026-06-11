<?php

namespace Tests\Feature;

use App\Models\Modules\Kitchen\Domain\KitchenTicket;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\DemoSeederTestSetup;
use Tests\TestCase;

class DemoSeederKdsStationRoutingTest extends TestCase
{
    use DemoSeederTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDemoSeederEnvironment();
    }

    public function test_station_showcase_order_splits_kds_tickets_by_station(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $order = Order::query()->where('code', 'DEMO-SUNSET-STATION-SHOWCASE')->firstOrFail();

        $this->assertSame(3, KitchenTicket::query()->where('order_id', $order->id)->count());
        $this->assertDatabaseMissing('kitchen_tickets', [
            'order_id' => $order->id,
            'station_code' => 'cashier',
        ]);

        $this->assertDatabaseHas('kitchen_ticket_items', [
            'item_name_snapshot' => 'Nasi Goreng Nusantara',
            'station_code' => 'kitchen',
        ]);
        $this->assertDatabaseHas('kitchen_ticket_items', [
            'item_name_snapshot' => 'Es Teh Manis',
            'station_code' => 'bar',
        ]);
        $this->assertDatabaseHas('kitchen_ticket_items', [
            'item_name_snapshot' => 'Croissant',
            'station_code' => 'dessert',
        ]);
    }

    public function test_kds_status_showcase_has_mixed_statuses(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $statuses = DB::table('kitchen_tickets')
            ->join('orders', 'orders.id', '=', 'kitchen_tickets.order_id')
            ->where('orders.outlet_id', $outlet->id)
            ->where('orders.code', 'like', 'DEMO-SUNSET-KDS-STATUS-%')
            ->pluck('kitchen_tickets.status')
            ->unique()
            ->values()
            ->all();

        foreach (['queued', 'preparing', 'ready', 'served'] as $expected) {
            $this->assertContains($expected, $statuses, "Missing KDS status {$expected}");
        }
    }
}
