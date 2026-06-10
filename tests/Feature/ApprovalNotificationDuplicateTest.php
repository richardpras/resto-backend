<?php

namespace Tests\Feature;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Notifications\Services\ApprovalNotificationService;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ApprovalNotificationDuplicateTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_repeated_approval_notification_create_is_idempotent(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('ADUP');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $service = app(NotificationService::class);

        $first = $service->create(
            (int) $outlet->id,
            (int) $user->id,
            UserNotification::SEVERITY_SUCCESS,
            UserNotification::MODULE_PROCUREMENT,
            ApprovalNotificationService::TYPE_PURCHASE_REQUEST_APPROVED,
            '99',
            'Purchase request approved',
            'PR-0099 was approved.',
            '/purchases?tab=requests&id=99',
        );

        $second = $service->create(
            (int) $outlet->id,
            (int) $user->id,
            UserNotification::SEVERITY_SUCCESS,
            UserNotification::MODULE_PROCUREMENT,
            ApprovalNotificationService::TYPE_PURCHASE_REQUEST_APPROVED,
            '99',
            'Purchase request approved (retry)',
            'Duplicate attempt',
            '/purchases?tab=requests&id=99',
        );

        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame(1, UserNotification::query()->where('source_type', ApprovalNotificationService::TYPE_PURCHASE_REQUEST_APPROVED)->count());
    }

    private function createOutlet(string $prefix): Outlet
    {
        return Outlet::query()->create([
            'name' => $prefix.' Outlet',
            'code' => strtolower($prefix).'-'.uniqid(),
            'status' => 'active',
        ]);
    }
}
