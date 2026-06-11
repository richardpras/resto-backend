<?php

namespace Tests\Concerns;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Print\Domain\PrinterRoute;
use App\Models\Modules\Production\Domain\ProductionStation;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Production\Services\ProductionStationProvisioner;
use Illuminate\Support\Facades\DB;

trait CashierStationValidationFixture
{
    /** @return array<string, ProductionStation> */
    protected function provisionCashierValidationStations(Outlet $outlet): array
    {
        $stations = app(ProductionStationProvisioner::class)->ensureForOutlet($outlet, null, 1);
        $indexed = [];
        foreach ($stations as $station) {
            $indexed[(string) $station->code] = $station;
        }

        $cashier = $indexed['cashier'];
        $this->assertFalse($cashier->kds_enabled);
        $this->assertFalse($cashier->print_enabled);

        return $indexed;
    }

    /**
     * @return array{nasi: MenuItem, esTeh: MenuItem, rokok: MenuItem}
     */
    protected function createCashierValidationMenuItems(Outlet $outlet, array $stations): array
    {
        $nasi = $this->createCashierValidationMenuItem($outlet, 'Nasi Goreng', $stations['kitchen']);
        $esTeh = $this->createCashierValidationMenuItem($outlet, 'Es Teh', $stations['bar'], 'Beverage');
        $rokok = $this->createCashierValidationMenuItem($outlet, 'Rokok Marlboro', $stations['cashier'], 'Retail');

        return compact('nasi', 'esTeh', 'rokok');
    }

    protected function createCashierValidationMenuItem(
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

    protected function attachRetailPackRecipe(Outlet $outlet, MenuItem $menuItem, float $initialStock = 50): Ingredient
    {
        $ingredient = Ingredient::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Rokok Pack SKU',
            'type' => 'ingredient',
            'unit' => 'pcs',
            'stock' => $initialStock,
            'min' => 5,
            'price' => 8000,
        ]);

        DB::table('inventory_stocks')->insert([
            'ingredient_id' => $ingredient->id,
            'outlet_id' => $outlet->id,
            'stock' => $initialStock,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('menu_recipes')->insert([
            'menu_item_id' => $menuItem->id,
            'inventory_item_id' => $ingredient->id,
            'quantity' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $ingredient;
    }

    /**
     * @return array{orderId: int, subtotal: float}
     */
    protected function createConfirmedCashierValidationOrder(
        Outlet $outlet,
        MenuItem $nasi,
        MenuItem $esTeh,
        MenuItem $rokok,
    ): array {
        $subtotal = (float) $nasi->price + (float) $esTeh->price + (float) $rokok->price;

        $response = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outlet->id,
            'code' => 'CASH-VAL-'.uniqid(),
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'serviceMode' => 'takeaway',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => (string) $nasi->id, 'name' => $nasi->name, 'qty' => 1, 'price' => (float) $nasi->price],
                ['id' => (string) $esTeh->id, 'name' => $esTeh->name, 'qty' => 1, 'price' => (float) $esTeh->price],
                ['id' => (string) $rokok->id, 'name' => $rokok->name, 'qty' => 1, 'price' => (float) $rokok->price],
            ],
            'subtotal' => $subtotal,
            'tax' => 0,
            'total' => $subtotal,
            'payments' => [],
        ]);
        $response->assertCreated();

        return [
            'orderId' => (int) $response->json('data.id'),
            'subtotal' => $subtotal,
        ];
    }

    protected function payCashierValidationOrder(int $orderId, float $amount): void
    {
        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [
                ['method' => 'cash', 'amount' => $amount],
            ],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'paid');
    }

    protected function seedKitchenBarPrintRoutes(Outlet $outlet, array $stations): void
    {
        $kitchenProfile = PrinterProfile::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'kitchen-cashier-val',
            'name' => 'Kitchen Printer',
            'station' => 'kitchen',
            'connection_type' => 'lan',
            'is_active' => true,
        ]);
        $barProfile = PrinterProfile::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'code' => 'bar-cashier-val',
            'name' => 'Bar Printer',
            'station' => 'bar',
            'connection_type' => 'lan',
            'is_active' => true,
        ]);

        $this->createCashierValidationStationRoute($outlet, $kitchenProfile, $stations['kitchen']);
        $this->createCashierValidationStationRoute($outlet, $barProfile, $stations['bar']);
    }

    protected function createCashierValidationStationRoute(
        Outlet $outlet,
        PrinterProfile $profile,
        ProductionStation $station,
    ): PrinterRoute {
        return PrinterRoute::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'printer_profile_id' => $profile->id,
            'print_type' => 'kitchen',
            'route_scope' => 'production_station',
            'production_station_id' => $station->id,
            'station_code' => strtolower((string) $station->code),
            'station' => strtolower((string) $station->code),
            'priority' => 10,
            'is_active' => true,
        ]);
    }
}
