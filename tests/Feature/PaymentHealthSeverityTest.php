<?php

namespace Tests\Feature;

use App\Modules\Payments\Services\PaymentHealthSeverityEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\PaymentHealthTestFixture;
use Tests\TestCase;

class PaymentHealthSeverityTest extends TestCase
{
    use PaymentHealthTestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        \Illuminate\Support\Facades\Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_health_endpoint_exposes_intelligence_fields(): void
    {
        Config::set('payments.providers.xendit.secret_key', 'xnd_secret');
        Config::set('payments.providers.xendit.webhook_token', 'wh_token');
        Config::set('payments.providers.xendit.qris_callback_url', 'https://api.example.com/webhooks/xendit');

        $outlet = $this->createPaymentHealthOutlet();
        $this->actingAsSettingsManager();

        $this->getJson('/api/v1/payments/health?provider=xendit&outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'provider',
                    'healthy',
                    'status',
                    'healthSeverity',
                    'paymentSuccessRate',
                    'webhookSuccessRate',
                    'providerRanking',
                ],
            ]);
    }

    public function test_severity_engine_rules(): void
    {
        $engine = app(PaymentHealthSeverityEngine::class);

        $this->assertSame('warning', $engine->rateSeverity(98.5));
        $this->assertSame('high', $engine->rateSeverity(93.0));
        $this->assertSame('critical', $engine->rateSeverity(85.0));
        $this->assertSame('healthy', $engine->rateSeverity(99.5));

        $this->assertSame('warning', $engine->stalePaymentsSeverity(8));
        $this->assertSame('high', $engine->stalePaymentsSeverity(25));
        $this->assertSame('critical', $engine->stalePaymentsSeverity(60));

        $this->assertSame('critical', $engine->aggregateSeverity(['healthy', 'warning', 'critical']));
    }
}
