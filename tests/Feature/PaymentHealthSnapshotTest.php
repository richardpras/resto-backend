<?php

namespace Tests\Feature;

use App\Models\Modules\Payments\Domain\PaymentHealthSnapshot;
use App\Modules\Payments\Services\PaymentHealthSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\PaymentHealthTestFixture;
use Tests\TestCase;

class PaymentHealthSnapshotTest extends TestCase
{
    use PaymentHealthTestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Config::set('payments.providers.xendit.secret_key', 'xnd_secret');
        Config::set('payments.providers.xendit.webhook_token', 'wh_token');
        Config::set('payments.providers.xendit.qris_callback_url', 'https://api.example.com/webhooks/xendit');
    }

    public function test_snapshot_command_persists_daily_row(): void
    {
        $outlet = $this->createPaymentHealthOutlet();

        $this->artisan('payment:health-snapshot', [
            '--outletId' => (int) $outlet->id,
            '--provider' => 'xendit',
        ])->assertSuccessful();

        $this->assertDatabaseHas('payment_health_snapshots', [
            'outlet_id' => (int) $outlet->id,
            'provider' => 'xendit',
            'snapshot_date' => now()->toDateString(),
        ]);
    }

    public function test_snapshot_service_is_idempotent_per_day(): void
    {
        $outlet = $this->createPaymentHealthOutlet();
        $service = app(PaymentHealthSnapshotService::class);

        $first = $service->captureForOutletProvider((int) $outlet->id, 'xendit');
        $second = $service->captureForOutletProvider((int) $outlet->id, 'xendit');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(
            1,
            PaymentHealthSnapshot::query()
                ->where('outlet_id', (int) $outlet->id)
                ->where('provider', 'xendit')
                ->where('snapshot_date', now()->toDateString())
                ->count(),
        );
    }
}
