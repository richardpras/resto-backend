<?php

namespace Tests\Feature;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Support\Str;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class NotificationUnreadCountTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_unread_count_endpoint_returns_unread_total(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('UNC');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $service = app(NotificationService::class);
        $first = $service->create(
            (int) $outlet->id,
            (int) $user->id,
            UserNotification::SEVERITY_INFO,
            UserNotification::MODULE_SYSTEM,
            'test',
            '1',
            'Unread one',
            'Message',
        );
        $service->create(
            (int) $outlet->id,
            (int) $user->id,
            UserNotification::SEVERITY_INFO,
            UserNotification::MODULE_SYSTEM,
            'test',
            '2',
            'Unread two',
            'Message',
        );
        $service->markRead($user, (int) $first->id);

        $this->getJson('/api/v1/notifications/unread-count?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonPath('count', 1);
    }

    private function createOutlet(string $prefix): Outlet
    {
        return Outlet::query()->create([
            'name' => $prefix.' Outlet',
            'code' => strtoupper(Str::random(6)),
            'status' => 'active',
        ]);
    }
}
