<?php

namespace Tests\Feature;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Modules\Notifications\Services\Adapters\FailedJobNotificationAdapter;
use App\Modules\System\Services\FailedJobSeverityEngine;
use App\Modules\System\Services\FailedJobSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\FailedJobTestFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class FailedJobNotificationTest extends TestCase
{
    use FailedJobTestFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_job_failed_notification_persisted(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createFailedJobOutlet();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        app(FailedJobNotificationAdapter::class)->notifyJobFailed(
            (int) $outlet->id,
            'RecoverStalePaymentsJob',
            FailedJobSeverityEngine::JOB_TIER_CRITICAL,
            'uuid-test-1',
        );

        $this->assertDatabaseHas('user_notifications', [
            'outlet_id' => (int) $outlet->id,
            'source_module' => UserNotification::MODULE_SYSTEM,
            'source_type' => FailedJobNotificationAdapter::TYPE_FAILED_JOB,
            'source_id' => sprintf('%d-%s-%s', (int) $outlet->id, 'RecoverStalePaymentsJob', FailedJobSeverityEngine::JOB_TIER_CRITICAL),
            'action_url' => '/system/failed-jobs',
        ]);
    }

    public function test_duplicate_notification_prevented(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createFailedJobOutlet();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $adapter = app(FailedJobNotificationAdapter::class);
        $adapter->notifyJobFailed((int) $outlet->id, 'RecoverStalePaymentsJob', FailedJobSeverityEngine::JOB_TIER_CRITICAL, 'uuid-a');
        $adapter->notifyJobFailed((int) $outlet->id, 'RecoverStalePaymentsJob', FailedJobSeverityEngine::JOB_TIER_CRITICAL, 'uuid-b');

        $count = UserNotification::query()
            ->where('user_id', (int) $user->id)
            ->where('source_type', FailedJobNotificationAdapter::TYPE_FAILED_JOB)
            ->count();

        $this->assertSame(1, $count);
    }

    public function test_monitor_notifies_on_critical_threshold(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createFailedJobOutlet();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        for ($i = 0; $i < 6; $i++) {
            $this->seedFailedJob('Payments\\RecoverStalePaymentsJob', 'payments-recovery', (int) $outlet->id);
        }

        app(FailedJobSnapshotService::class)->monitorAndNotify();

        $this->assertDatabaseHas('user_notifications', [
            'outlet_id' => (int) $outlet->id,
            'source_type' => FailedJobNotificationAdapter::TYPE_FAILED_JOB_CRITICAL,
        ]);
    }
}
