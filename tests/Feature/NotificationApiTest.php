<?php

namespace Tests\Feature;

use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Support\Str;
use App\Modules\Notifications\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class NotificationApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_list_notifications_returns_only_authenticated_user_records(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $other = User::factory()->create();
        $outlet = $this->createOutlet('NTF');

        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $service = app(NotificationService::class);
        $service->create(
            (int) $outlet->id,
            (int) $user->id,
            UserNotification::SEVERITY_WARNING,
            UserNotification::MODULE_MONITORING,
            'printer_queue_failures',
            (string) $outlet->id,
            'Printer queue failures',
            '1 failed',
            '/',
        );
        $service->create(
            (int) $outlet->id,
            (int) $other->id,
            UserNotification::SEVERITY_CRITICAL,
            UserNotification::MODULE_ACCOUNTING,
            'posting_failure',
            '99',
            'Other user alert',
            'Hidden',
            '/accounting?tab=health',
        );

        $response = $this->getJson('/api/v1/notifications?outletId='.(int) $outlet->id);

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Printer queue failures');
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
