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

class NotificationDuplicateProtectionTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_create_is_idempotent_for_same_source_keys(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('DED');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $service = app(NotificationService::class);

        $first = $service->create(
            (int) $outlet->id,
            (int) $user->id,
            UserNotification::SEVERITY_CRITICAL,
            UserNotification::MODULE_INVENTORY,
            'critical_stock',
            '42',
            'Critical stock: Chicken',
            'Below minimum',
            '/inventory',
        );

        $second = $service->create(
            (int) $outlet->id,
            (int) $user->id,
            UserNotification::SEVERITY_CRITICAL,
            UserNotification::MODULE_INVENTORY,
            'critical_stock',
            '42',
            'Critical stock: Chicken (duplicate attempt)',
            'Should not create a new row',
            '/inventory',
        );

        $this->assertSame((int) $first->id, (int) $second->id);
        $this->assertSame(1, UserNotification::query()->count());
        $this->assertSame('Critical stock: Chicken', $second->title);
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
