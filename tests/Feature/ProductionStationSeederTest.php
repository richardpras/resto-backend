<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Production\Domain\ProductionStation;
use App\Models\Modules\Settings\Domain\Outlet;
use Database\Seeders\Demo\DemoCatalogSeeder;
use Database\Seeders\Demo\DemoFoundationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductionStationSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_seeder_creates_outlet_stations_and_assigns_menu_items(): void
    {
        $this->seed(DemoFoundationSeeder::class);
        $this->seed(DemoCatalogSeeder::class);

        $sunset = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $mountain = Outlet::query()->where('code', 'DEMO-MOUNTAIN')->firstOrFail();

        $this->assertEqualsCanonicalizing(
            ['kitchen', 'bar', 'cashier', 'dessert'],
            ProductionStation::query()->where('outlet_id', $sunset->id)->pluck('code')->all(),
        );
        $this->assertEqualsCanonicalizing(
            ['kitchen', 'bar', 'bakery', 'cashier'],
            ProductionStation::query()->where('outlet_id', $mountain->id)->pluck('code')->all(),
        );

        $nasi = MenuItem::query()->where('outlet_id', $sunset->id)->where('name', 'Nasi Goreng Nusantara')->firstOrFail();
        $this->assertSame('kitchen', $nasi->productionStation?->code);

        $esTeh = MenuItem::query()->where('outlet_id', $sunset->id)->where('name', 'Es Teh Manis')->firstOrFail();
        $this->assertSame('bar', $esTeh->productionStation?->code);

        $rokok = MenuItem::query()->where('outlet_id', $sunset->id)->where('name', 'Rokok Marlboro')->firstOrFail();
        $this->assertSame('cashier', $rokok->productionStation?->code);

        $croissant = MenuItem::query()->where('outlet_id', $mountain->id)->where('name', 'Croissant')->firstOrFail();
        $this->assertSame('bakery', $croissant->productionStation?->code);
    }

    public function test_provisioner_seeds_all_default_stations_when_codes_not_specified(): void
    {
        $outlet = Outlet::query()->create([
            'code' => 'ps-default-'.uniqid('', true),
            'name' => 'Default Station Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
        ]);

        $stations = app(\App\Modules\Production\Services\ProductionStationProvisioner::class)->ensureForOutlet($outlet, null, 1);

        $this->assertCount(5, $stations);
        $this->assertEqualsCanonicalizing(
            ['kitchen', 'bar', 'cashier', 'dessert', 'bakery'],
            ProductionStation::query()->where('outlet_id', $outlet->id)->pluck('code')->all(),
        );

        $cashier = ProductionStation::query()->where('outlet_id', $outlet->id)->where('code', 'cashier')->firstOrFail();
        $this->assertFalse($cashier->kds_enabled);
        $this->assertFalse($cashier->print_enabled);
    }
}
