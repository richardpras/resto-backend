<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class AuditEntityHistoryTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_entity_history_returns_chronological_timeline(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('History Outlet');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        PosEventLog::query()->create([
            'outlet_id' => $outlet->id,
            'actor_user_id' => $user->id,
            'event_type' => 'purchase_order_created',
            'entity_type' => 'purchase_order',
            'entity_id' => 123,
            'payload' => ['status' => 'draft'],
            'occurred_at' => now()->subHour(),
        ]);

        PosEventLog::query()->create([
            'outlet_id' => $outlet->id,
            'actor_user_id' => $user->id,
            'event_type' => 'purchase_order_approved',
            'entity_type' => 'purchase_order',
            'entity_id' => 123,
            'payload' => ['status' => 'approved'],
            'occurred_at' => now(),
        ]);

        $response = $this->getJson('/api/v1/audit-center/entity-history?entityType=purchase_order&entityId=123');

        $response->assertOk();
        $response->assertJsonCount(2, 'data');
        $response->assertJsonPath('data.0.action', 'purchase_order_created');
        $response->assertJsonPath('data.1.action', 'purchase_order_approved');
    }

    private function createOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'hist-'.uniqid(),
        ]);
    }
}
