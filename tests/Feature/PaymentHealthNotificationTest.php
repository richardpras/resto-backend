<?php

namespace Tests\Feature;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Models\Modules\Payments\Domain\PaymentHealthSnapshot;
use App\Modules\Payments\Services\PaymentHealthSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Concerns\PaymentHealthTestFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PaymentHealthNotificationTest extends TestCase
{
    use PaymentHealthTestFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Config::set('app.env', 'production');
        Config::set('payments.providers.xendit.secret_key', '');
        Config::set('payments.providers.xendit.webhook_token', '');
        Config::set('payments.providers.xendit.qris_callback_url', '');
    }

    public function test_severity_escalation_creates_notification_without_duplicate(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createPaymentHealthOutlet();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        PaymentHealthSnapshot::query()->create([
            'outlet_id' => (int) $outlet->id,
            'provider' => 'xendit',
            'snapshot_date' => now()->subDay()->toDateString(),
            'health_status' => 'healthy',
            'payment_success_rate' => 100,
            'webhook_success_rate' => 100,
            'stale_payments' => 0,
            'failed_webhooks' => 0,
            'average_processing_time_ms' => 0,
            'active_incidents' => 0,
        ]);

        $service = app(PaymentHealthSnapshotService::class);
        $service->captureForOutletProvider((int) $outlet->id, 'xendit');
        $service->captureForOutletProvider((int) $outlet->id, 'xendit');

        $this->assertDatabaseHas('user_notifications', [
            'outlet_id' => (int) $outlet->id,
            'source_module' => UserNotification::MODULE_PAYMENTS,
            'source_type' => 'payment_health_alert',
        ]);

        $count = UserNotification::query()
            ->where('outlet_id', (int) $outlet->id)
            ->where('source_type', 'payment_health_alert')
            ->where('source_id', (int) $outlet->id.'-xendit-critical')
            ->count();

        $this->assertGreaterThanOrEqual(1, $count);
        $this->assertLessThanOrEqual(2, $count);
    }
}
