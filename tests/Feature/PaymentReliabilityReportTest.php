<?php

namespace Tests\Feature;

use App\Models\Modules\Payments\Domain\PaymentHealthSnapshot;
use App\Models\Modules\Payments\Domain\PaymentIncident;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PaymentHealthTestFixture;
use Tests\TestCase;

class PaymentReliabilityReportTest extends TestCase
{
    use PaymentHealthTestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        \Illuminate\Support\Facades\Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_reliability_endpoint_ranks_providers(): void
    {
        $outlet = $this->createPaymentHealthOutlet();

        PaymentHealthSnapshot::query()->create([
            'outlet_id' => (int) $outlet->id,
            'provider' => 'xendit',
            'snapshot_date' => now()->subDay()->toDateString(),
            'health_status' => 'healthy',
            'payment_success_rate' => 99.5,
            'webhook_success_rate' => 99.8,
            'stale_payments' => 0,
            'failed_webhooks' => 0,
            'average_processing_time_ms' => 1200,
            'active_incidents' => 0,
        ]);

        PaymentHealthSnapshot::query()->create([
            'outlet_id' => (int) $outlet->id,
            'provider' => 'midtrans',
            'snapshot_date' => now()->subDay()->toDateString(),
            'health_status' => 'critical',
            'payment_success_rate' => 88.0,
            'webhook_success_rate' => 85.0,
            'stale_payments' => 10,
            'failed_webhooks' => 5,
            'average_processing_time_ms' => 2500,
            'active_incidents' => 1,
        ]);

        PaymentIncident::query()->create([
            'outlet_id' => (int) $outlet->id,
            'provider' => 'midtrans',
            'incident_type' => PaymentIncident::TYPE_PROVIDER_CRITICAL,
            'severity' => 'critical',
            'title' => 'Provider configuration critical',
            'description' => 'Test',
            'opened_at' => now()->subHours(2),
            'resolved_at' => now()->subHour(),
            'duration_minutes' => 60,
            'status' => PaymentIncident::STATUS_RESOLVED,
        ]);

        $this->actingAsSettingsManager();

        $response = $this->getJson('/api/v1/payments/health/reliability?outletId='.(int) $outlet->id);
        $response->assertOk()->assertJsonStructure([
            'data' => [
                '*' => ['provider', 'uptimePercent', 'incidents', 'avgResolutionMinutes', 'paymentSuccessRate'],
            ],
        ]);

        $data = $response->json('data');
        $this->assertIsArray($data);
        $byProvider = collect($data)->keyBy('provider');
        $this->assertGreaterThan(
            (float) $byProvider->get('midtrans')['uptimePercent'],
            (float) $byProvider->get('xendit')['uptimePercent'],
        );
        $this->assertSame(1, (int) $byProvider->get('midtrans')['incidents']);
    }
}
