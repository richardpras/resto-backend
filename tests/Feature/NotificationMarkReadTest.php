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

class NotificationMarkReadTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_mark_read_and_mark_all_read_endpoints(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('MRD');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $service = app(NotificationService::class);
        $one = $service->create(
            (int) $outlet->id,
            (int) $user->id,
            UserNotification::SEVERITY_WARNING,
            UserNotification::MODULE_PAYMENTS,
            'stale_payments',
            (string) $outlet->id,
            'Stale payments',
            'Threshold exceeded',
        );
        $service->create(
            (int) $outlet->id,
            (int) $user->id,
            UserNotification::SEVERITY_CRITICAL,
            UserNotification::MODULE_ACCOUNTING,
            'gift_card_variance',
            (string) $outlet->id,
            'Gift card variance',
            'Variance detected',
        );

        $this->patchJson('/api/v1/notifications/'.$one->id.'/read')
            ->assertOk()
            ->assertJsonPath('data.isRead', true);

        $this->getJson('/api/v1/notifications/unread-count?outletId='.(int) $outlet->id)
            ->assertJsonPath('count', 1);

        $this->patchJson('/api/v1/notifications/read-all', ['outletId' => (int) $outlet->id])
            ->assertOk()
            ->assertJsonPath('count', 1);

        $this->getJson('/api/v1/notifications/unread-count?outletId='.(int) $outlet->id)
            ->assertJsonPath('count', 0);
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
