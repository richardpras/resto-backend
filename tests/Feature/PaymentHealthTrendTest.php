<?php

namespace Tests\Feature;

use App\Models\Modules\Payments\Domain\PaymentHealthSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\PaymentHealthTestFixture;
use Tests\TestCase;

class PaymentHealthTrendTest extends TestCase
{
    use PaymentHealthTestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        \Illuminate\Support\Facades\Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_trends_endpoint_returns_series(): void
    {
        $outlet = $this->createPaymentHealthOutlet();
        $date = now()->subDays(2)->toDateString();

        PaymentHealthSnapshot::query()->create([
            'outlet_id' => (int) $outlet->id,
            'provider' => 'xendit',
            'snapshot_date' => $date,
            'health_status' => 'warning',
            'payment_success_rate' => 97.5,
            'webhook_success_rate' => 98.0,
            'stale_payments' => 6,
            'failed_webhooks' => 2,
            'average_processing_time_ms' => 900,
            'active_incidents' => 1,
        ]);

        $this->actingAsSettingsManager();

        $this->getJson('/api/v1/payments/health/trends?outletId='.(int) $outlet->id.'&provider=xendit&startDate='.$date.'&endDate='.$date)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'providerTrend',
                    'paymentSuccessTrend',
                    'webhookTrend',
                    'incidentTrend',
                ],
            ])
            ->assertJsonPath('data.paymentSuccessTrend.0.rate', 97.5);
    }
}
