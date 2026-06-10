<?php

namespace Tests\Feature;

use App\Modules\Payments\Exceptions\PaymentConfigurationException;
use App\Modules\Payments\Services\PaymentConfigurationHealthService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class PaymentConfigurationHealthTest extends TestCase
{
    use RefreshDatabase;

    public function test_healthy_xendit_configuration_in_production(): void
    {
        Config::set('app.env', 'production');
        Config::set('payments.providers.xendit.secret_key', 'xnd_secret');
        Config::set('payments.providers.xendit.webhook_token', 'wh_token');
        Config::set('payments.providers.xendit.qris_callback_url', 'https://api.example.com/webhooks/xendit');

        $report = app(PaymentConfigurationHealthService::class)->assessProvider('xendit');

        $this->assertTrue($report['healthy']);
        $this->assertSame('healthy', $report['status']);
        $this->assertFalse($report['wouldUseStub']);
        $this->assertSame([], $report['missing']);
    }

    public function test_missing_credentials_reported_as_critical(): void
    {
        Config::set('app.env', 'production');
        Config::set('payments.providers.xendit.secret_key', '');
        Config::set('payments.providers.xendit.webhook_token', '');
        Config::set('payments.providers.xendit.qris_callback_url', '');

        $report = app(PaymentConfigurationHealthService::class)->assessProvider('xendit');

        $this->assertFalse($report['healthy']);
        $this->assertSame('critical', $report['status']);
        $this->assertContains('XENDIT_SECRET_KEY', $report['missing']);
        $this->assertContains('XENDIT_WEBHOOK_TOKEN', $report['missing']);
        $this->assertContains('XENDIT_QRIS_CALLBACK_URL', $report['missing']);
        $this->assertContains('PRODUCTION_STUB_FORBIDDEN', $report['missing']);
    }

    public function test_production_boot_blocks_default_stub_provider(): void
    {
        Config::set('app.env', 'production');
        Config::set('payments.strict_production_boot', true);
        Config::set('payments.default_provider', 'midtrans');
        Config::set('payments.providers.midtrans.server_key', '');
        Config::set('payments.providers.midtrans.client_key', '');
        Config::set('payments.providers.midtrans.webhook_secret', '');

        $this->expectException(PaymentConfigurationException::class);
        $this->expectExceptionMessage('Payment provider configured but credentials are missing.');

        app(PaymentConfigurationHealthService::class)->assertProductionBootReady();
    }

    public function test_development_allows_stub_provider_assessment(): void
    {
        Config::set('app.env', 'local');
        Config::set('payments.providers.manual.server_key', '');
        Config::set('payments.providers.manual.client_key', '');
        Config::set('payments.providers.manual.webhook_secret', '');

        $report = app(PaymentConfigurationHealthService::class)->assessProvider('manual');

        $this->assertTrue($report['wouldUseStub']);
        $this->assertNotContains('PRODUCTION_STUB_FORBIDDEN', $report['missing']);
    }
}
