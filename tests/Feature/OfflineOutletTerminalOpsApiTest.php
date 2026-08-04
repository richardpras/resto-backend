<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Terminals\Support\TerminalOperationType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class OfflineOutletTerminalOpsApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_operation_taxonomy_includes_offline_outlet_ops(): void
    {
        $all = TerminalOperationType::all();
        $this->assertContains(TerminalOperationType::MEMBER_QUICK_CREATE, $all);
        $this->assertContains(TerminalOperationType::MENU_ITEM_AVAILABILITY, $all);
        $this->assertContains(TerminalOperationType::INVENTORY_STOCK_MOVEMENT_CREATE, $all);
        $this->assertContains(TerminalOperationType::INVENTORY_STOCKTAKE_SUBMIT, $all);
    }

    public function test_member_quick_create_replays_via_sync_batch(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'Offline Member Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'om-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        $this->postJson('/api/v1/terminals/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'device-offline-member-1',
            'displayLabel' => 'Android POS',
        ])->assertOk();

        $response = $this->postJson('/api/v1/sync/operations/batch', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => 'device-offline-member-1',
            'operations' => [[
                'fingerprint' => 'fp-member-quick-'.uniqid(),
                'operationType' => TerminalOperationType::MEMBER_QUICK_CREATE,
                'clientOccurredAt' => now()->toIso8601String(),
                'payload' => [
                    'outletId' => (int) $outlet->id,
                    'fullName' => 'Offline Guest',
                    'phone' => '08123456789',
                    'clientLocalRef' => 'local-member:test-1',
                ],
            ]],
        ]);

        $response->assertOk()
            ->assertJsonPath('data.results.0.status', 'applied')
            ->assertJsonPath('data.results.0.outcomeSummary.entity', 'member');
        $this->assertDatabaseHas('members', [
            'outlet_id' => $outlet->id,
            'phone' => '08123456789',
        ]);
    }
}
