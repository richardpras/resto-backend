<?php

namespace Tests\Feature;

use App\Models\Modules\Production\Domain\ProductionStation;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DemoSeederTestSetup;
use Tests\TestCase;

class DemoSeederProductionStationTest extends TestCase
{
    use DemoSeederTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDemoSeederEnvironment();
    }

    public function test_sunset_cafe_has_required_production_stations(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $this->assertStationFlags($outlet, [
            'kitchen' => ['kds' => true, 'print' => true],
            'bar' => ['kds' => true, 'print' => true],
            'dessert' => ['kds' => true, 'print' => true],
            'cashier' => ['kds' => false, 'print' => false],
        ]);
    }

    public function test_mountain_cafe_has_required_production_stations(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-MOUNTAIN')->firstOrFail();
        $this->assertStationFlags($outlet, [
            'kitchen' => ['kds' => true, 'print' => true],
            'bar' => ['kds' => true, 'print' => true],
            'bakery' => ['kds' => true, 'print' => true],
            'cashier' => ['kds' => false, 'print' => false],
        ]);
    }

    public function test_demo_menu_items_have_production_stations(): void
    {
        foreach (['DEMO-SUNSET', 'DEMO-MOUNTAIN'] as $code) {
            $outlet = Outlet::query()->where('code', $code)->firstOrFail();
            $unassigned = \App\Models\Modules\Menu\Domain\MenuItem::query()
                ->where('outlet_id', $outlet->id)
                ->where('available', true)
                ->whereNull('production_station_id')
                ->count();

            $this->assertSame(0, $unassigned, "{$code} has unassigned active menu items");
        }
    }

    /** @param array<string, array{kds: bool, print: bool}> $expected */
    private function assertStationFlags(Outlet $outlet, array $expected): void
    {
        foreach ($expected as $code => $flags) {
            $station = ProductionStation::query()
                ->where('outlet_id', $outlet->id)
                ->where('code', $code)
                ->first();

            $this->assertNotNull($station, "Missing station {$code} for {$outlet->code}");
            $this->assertSame($flags['kds'], (bool) $station->kds_enabled, "{$code} kds_enabled");
            $this->assertSame($flags['print'], (bool) $station->print_enabled, "{$code} print_enabled");
        }
    }
}
