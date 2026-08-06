<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosSessionCashMovement;
use App\Models\Modules\Settings\Domain\Outlet;
use Database\Seeders\AccountingPostingMappingsSeeder;
use Database\Seeders\TemplateAccountingSeeder;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\ProductionStationTestFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PosSessionCashMovementTest extends TestCase
{
    use ProductionStationTestFixture;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seed(UserManagementPermissionsSeeder::class);
        $this->seed(TemplateAccountingSeeder::class);
        $this->seed(AccountingPostingMappingsSeeder::class);
    }

    private function createOutletWithFloat(float $float = 500000): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Cash Movement Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'pcm-'.uniqid('', true),
            'default_cash_float' => $float,
        ]);
    }

    public function test_cash_out_updates_close_preview_expected(): void
    {
        $outlet = $this->createOutletWithFloat(500000);
        $user = $this->createUserWithPermission('pos.use', $outlet);
        Passport::actingAs($user);

        $sessionId = (int) $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $outlet->id,
            'openingCash' => 500000,
        ])->assertCreated()->json('data.id');

        $this->postJson('/api/v1/pos-sessions/'.$sessionId.'/cash-movements', [
            'direction' => 'out',
            'amount' => 25000,
            'category' => 'iuran',
            'notes' => 'RT fee',
            'clientLocalRef' => 'local-cash-mv:test-out-1',
        ])->assertCreated()
            ->assertJsonPath('data.direction', 'out')
            ->assertJsonPath('data.amount', 25000);

        $this->getJson('/api/v1/pos-sessions/'.$sessionId.'/close-preview')
            ->assertOk()
            ->assertJsonPath('data.drawerReconciliation.cashOut', 25000)
            ->assertJsonPath('data.drawerReconciliation.expected', 475000);

        $this->assertDatabaseHas('pos_session_cash_movements', [
            'pos_session_id' => $sessionId,
            'direction' => 'out',
            'category' => 'iuran',
        ]);
    }

    public function test_cash_in_and_idempotent_client_local_ref(): void
    {
        $outlet = $this->createOutletWithFloat(500000);
        $user = $this->createUserWithPermission('pos.use', $outlet);
        Passport::actingAs($user);

        $sessionId = (int) $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $outlet->id,
            'openingCash' => 500000,
        ])->json('data.id');

        $first = $this->postJson('/api/v1/pos-sessions/'.$sessionId.'/cash-movements', [
            'direction' => 'in',
            'amount' => 100000,
            'category' => 'dari_brankas',
            'clientLocalRef' => 'local-cash-mv:test-in-1',
        ])->assertCreated();

        $id = (int) $first->json('data.id');

        $second = $this->postJson('/api/v1/pos-sessions/'.$sessionId.'/cash-movements', [
            'direction' => 'in',
            'amount' => 100000,
            'category' => 'dari_brankas',
            'clientLocalRef' => 'local-cash-mv:test-in-1',
        ])->assertCreated();

        $this->assertSame($id, (int) $second->json('data.id'));
        $this->assertSame(1, PosSessionCashMovement::query()->where('pos_session_id', $sessionId)->count());

        $this->getJson('/api/v1/pos-sessions/'.$sessionId.'/close-preview')
            ->assertOk()
            ->assertJsonPath('data.drawerReconciliation.cashIn', 100000)
            ->assertJsonPath('data.drawerReconciliation.expected', 600000);
    }

    public function test_rejects_cash_movement_on_closed_session(): void
    {
        $outlet = $this->createOutletWithFloat(500000);
        $user = $this->createUserWithPermission('pos.use', $outlet);
        Passport::actingAs($user);

        $sessionId = (int) $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $outlet->id,
            'openingCash' => 500000,
        ])->json('data.id');

        $this->postJson('/api/v1/pos-sessions/'.$sessionId.'/close', [
            'actualCash' => 500000,
        ])->assertOk();

        $this->postJson('/api/v1/pos-sessions/'.$sessionId.'/cash-movements', [
            'direction' => 'out',
            'amount' => 1000,
            'category' => 'operasional',
        ])->assertStatus(422);
    }

    public function test_terminal_sync_replays_cash_movement(): void
    {
        $outlet = $this->createOutletWithFloat(500000);
        $user = $this->createUserWithPermission('pos.use', $outlet);
        Passport::actingAs($user);

        $sessionId = (int) $this->postJson('/api/v1/pos-sessions/open', [
            'outletId' => $outlet->id,
            'openingCash' => 500000,
        ])->json('data.id');

        $deviceKey = 'cash-mv-device-'.$outlet->id;
        $this->postJson('/api/v1/terminals/register', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => $deviceKey,
        ])->assertOk();

        $fp = hash('sha256', 'cash-mv-'.$sessionId.'-sync');
        $this->postJson('/api/v1/sync/operations/batch', [
            'outletId' => (int) $outlet->id,
            'deviceKey' => $deviceKey,
            'operations' => [
                [
                    'fingerprint' => $fp,
                    'operationType' => \App\Modules\Terminals\Support\TerminalOperationType::POS_SESSION_CASH_MOVEMENT,
                    'payload' => [
                        'sessionId' => $sessionId,
                        'direction' => 'out',
                        'amount' => 15000,
                        'category' => 'operasional',
                        'clientLocalRef' => 'local-cash-mv:sync-1',
                    ],
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.results.0.status', 'applied');

        $this->assertDatabaseHas('pos_session_cash_movements', [
            'pos_session_id' => $sessionId,
            'amount' => 15000,
            'client_local_ref' => 'local-cash-mv:sync-1',
        ]);
    }
}
