<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletPaymentMethodConfig;
use App\Models\Modules\Settings\Domain\PaymentMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DemoSeederTestSetup;
use Tests\TestCase;

class DemoSeederPaymentSettingsTest extends TestCase
{
    use DemoSeederTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDemoSeederEnvironment();
    }

    public function test_outlet_payment_configs_enable_core_methods(): void
    {
        foreach (['DEMO-SUNSET', 'DEMO-MOUNTAIN'] as $code) {
            $outlet = Outlet::query()->where('code', $code)->firstOrFail();
            $enabled = OutletPaymentMethodConfig::query()
                ->where('outlet_id', $outlet->id)
                ->where('enabled', true)
                ->pluck('payment_method_code')
                ->all();

            $this->assertContains('cash', $enabled, "{$code} cash");
            $this->assertContains('manual_qris', $enabled, "{$code} manual_qris");
            $this->assertContains('gateway_qris', $enabled, "{$code} gateway_qris");
        }
    }

    public function test_legacy_payment_methods_have_clear_outlet_names(): void
    {
        $sunsetCash = PaymentMethod::query()->where('id', 'demo_A_cash')->first();
        $mountainCash = PaymentMethod::query()->where('id', 'demo_B_cash')->first();

        $this->assertNotNull($sunsetCash);
        $this->assertNotNull($mountainCash);
        $this->assertStringContainsString('Sunset Cafe', (string) $sunsetCash->name);
        $this->assertStringContainsString('Mountain Cafe', (string) $mountainCash->name);
    }
}
