<?php

namespace Tests\Feature;

use App\Models\Modules\Payments\Domain\PaymentIncident;
use App\Modules\Payments\Services\PaymentIncidentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\PaymentHealthTestFixture;
use Tests\TestCase;

class PaymentIncidentDetectionTest extends TestCase
{
    use PaymentHealthTestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Config::set('app.env', 'production');
        Config::set('payments.providers.xendit.secret_key', '');
        Config::set('payments.providers.xendit.webhook_token', '');
        Config::set('payments.providers.xendit.qris_callback_url', '');
    }

    public function test_incident_opened_when_provider_critical(): void
    {
        $outlet = $this->createPaymentHealthOutlet();
        $service = app(PaymentIncidentService::class);

        $service->checkOutletProvider((int) $outlet->id, 'xendit');

        $this->assertDatabaseHas('payment_incidents', [
            'outlet_id' => (int) $outlet->id,
            'provider' => 'xendit',
            'status' => PaymentIncident::STATUS_OPEN,
        ]);
    }

    public function test_incidents_endpoint_lists_open_incidents(): void
    {
        $outlet = $this->createPaymentHealthOutlet();
        PaymentIncident::query()->create([
            'outlet_id' => (int) $outlet->id,
            'provider' => 'xendit',
            'incident_type' => PaymentIncident::TYPE_WEBHOOK_SPIKE,
            'severity' => 'high',
            'title' => 'Webhook failure spike detected',
            'description' => 'Test incident',
            'opened_at' => now(),
            'status' => PaymentIncident::STATUS_OPEN,
        ]);

        $this->actingAsSettingsManager();

        $this->getJson('/api/v1/payments/incidents?outletId='.(int) $outlet->id.'&status=open')
            ->assertOk()
            ->assertJsonPath('data.0.provider', 'xendit')
            ->assertJsonPath('data.0.status', 'open');
    }
}
