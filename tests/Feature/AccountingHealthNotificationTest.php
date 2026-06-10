<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\AccountingHealthSnapshot;
use App\Models\Modules\Accounting\Domain\AccountingPostingFailure;
use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Modules\Accounting\Services\AccountingHealthSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AccountingHealthNotificationTest extends TestCase
{
    use AccountingRemediationFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_severity_escalation_creates_notification_without_duplicate(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet('Health Notify');

        AccountingHealthSnapshot::query()->create([
            'outlet_id' => (int) $outlet->id,
            'snapshot_date' => now()->subDay()->toDateString(),
            'posting_failures' => 0,
            'gift_card_variance' => 0,
            'inventory_variance' => 0,
            'payroll_variance' => 0,
            'procurement_variance' => 0,
            'severity' => 'healthy',
        ]);

        for ($i = 1; $i <= 8; $i++) {
            AccountingPostingFailure::query()->create([
                'source_type' => 'order_payment',
                'source_id' => 3000 + $i,
                'outlet_id' => (int) $outlet->id,
                'error_code' => AccountingPostingFailure::ERROR_POSTING,
                'error_message' => 'Escalation test',
                'status' => AccountingPostingFailure::STATUS_PENDING,
            ]);
        }

        $service = app(AccountingHealthSnapshotService::class);
        $service->captureForOutlet((int) $outlet->id);
        $service->captureForOutlet((int) $outlet->id);

        $this->assertDatabaseHas('user_notifications', [
            'outlet_id' => (int) $outlet->id,
            'source_module' => UserNotification::MODULE_ACCOUNTING,
            'source_type' => 'health_severity_escalation',
        ]);

        $this->assertSame(
            1,
            UserNotification::query()
                ->where('source_type', 'health_severity_escalation')
                ->where('outlet_id', (int) $outlet->id)
                ->count(),
        );
    }
}
