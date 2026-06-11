<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\SystemSetting;
use App\Modules\Orders\Services\TableQrService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DemoSeederTestSetup;
use Tests\TestCase;

class DemoSeederTableQrTest extends TestCase
{
    use DemoSeederTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDemoSeederEnvironment();
    }

    public function test_each_outlet_has_ten_qr_tables(): void
    {
        foreach (['DEMO-SUNSET' => 'A', 'DEMO-MOUNTAIN' => 'B'] as $code => $prefix) {
            $outlet = Outlet::query()->where('code', $code)->firstOrFail();
            $count = RestaurantTable::query()
                ->where('outlet_id', $outlet->id)
                ->where('code', 'like', $prefix.'%')
                ->whereNotNull('qr_public_id')
                ->where('qr_enabled', true)
                ->where('active', true)
                ->count();

            $this->assertSame(10, $count, "{$code} should have 10 QR tables");
        }
    }

    public function test_table_qr_urls_use_configured_customer_app_url(): void
    {
        $expectedBase = rtrim((string) env('DEMO_CUSTOMER_APP_URL', 'http://localhost:8080'), '/');
        $stored = SystemSetting::query()->value('customer_app_url');
        $this->assertSame($expectedBase, $stored);

        $table = RestaurantTable::query()
            ->where('qr_public_id', 'demo-sunset-a01')
            ->firstOrFail();

        $url = app(TableQrService::class)->buildQrUrl($table);
        $this->assertSame("{$expectedBase}/qr/demo-sunset-a01", $url);
    }
}
