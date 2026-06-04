<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\LoyaltyAnalyticsDashboardTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyAnalyticsPermissionTest extends TestCase
{
    use LoyaltyAnalyticsDashboardTestFixtures;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_outlet_isolation_blocks_foreign_dashboard_access(): void
    {
        $admin = $this->actingAsDashboardManager();
        $allowed = $this->createDashboardOutlet('allowed');
        $blocked = $this->createDashboardOutlet('blocked');
        $this->assignUserToOutlets($admin, [(int) $allowed->id]);

        $this->getJson('/api/v1/loyalty-analytics/dashboard?outletId='.$blocked->id)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId']);
    }

    public function test_permission_enforcement_requires_members_manage(): void
    {
        $viewer = $this->actingAsDashboardViewerWithoutPermission();
        $outlet = $this->createDashboardOutlet('perm');
        $this->assignUserToOutlets($viewer, [(int) $outlet->id]);

        $this->getJson('/api/v1/loyalty-analytics/dashboard?outletId='.$outlet->id)
            ->assertStatus(403);
    }
}
