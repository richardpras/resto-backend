<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AuditCenterApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_timeline_requires_authentication(): void
    {
        $this->getJson('/api/v1/audit-center')->assertUnauthorized();
    }

    public function test_timeline_returns_normalized_records(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Audit Outlet');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        PosEventLog::query()->create([
            'outlet_id' => $outlet->id,
            'actor_user_id' => $user->id,
            'event_type' => 'purchase_order_approved',
            'entity_type' => 'purchase_order',
            'entity_id' => 42,
            'payload' => ['documentNumber' => 'PO-0042'],
            'occurred_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/audit-center?module=purchase&outletId='.$outlet->id);

        $response->assertOk();
        $response->assertJsonPath('data.0.module', 'purchase');
        $response->assertJsonPath('data.0.entityType', 'purchase_order');
        $response->assertJsonPath('data.0.entityId', 42);
        $response->assertJsonPath('data.0.action', 'purchase_order_approved');
        $response->assertJsonPath('data.0.metadata.riskLevel', 'warning');
        $response->assertJsonStructure(['meta' => ['currentPage', 'lastPage', 'perPage', 'total']]);
    }

    public function test_summary_returns_dashboard_metrics(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Audit Summary Outlet');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        PosEventLog::query()->create([
            'outlet_id' => $outlet->id,
            'actor_user_id' => $user->id,
            'event_type' => 'reversal_created',
            'entity_type' => 'journal',
            'entity_id' => 7,
            'payload' => [],
            'occurred_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/audit-center/summary?outletId='.$outlet->id);

        $response->assertOk();
        $response->assertJsonPath('data.todayEvents', 1);
        $response->assertJsonPath('data.criticalEvents', 1);
        $response->assertJsonPath('data.activeUsers', 1);
    }

    private function createOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'audit-'.uniqid(),
        ]);
    }
}
