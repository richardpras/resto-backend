<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\AccountingSetting;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\ShiftClose\Domain\ShiftCloseRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ShiftCloseEngineTest extends TestCase
{
    use RefreshDatabase;
    use AccountingRemediationFixture;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_preflight_detects_open_bills(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Preflight Bills');
        Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'OPEN-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'completed',
            'payment_status' => 'unpaid',
            'total' => 10000,
            'subtotal' => 10000,
            'tax' => 0,
            'paid_total' => 0,
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/shift-close/preflight?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonPath('data.checks.openBills', 1)
            ->assertJsonPath('data.severity', 'healthy')
            ->assertJsonPath('data.ready', true)
            ->assertJsonMissingPath('data.warnings.0');
    }

    public function test_run_requires_confirm_when_warnings_present(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Confirm Warn');
        PosSession::query()->create([
            'outlet_id' => (int) $outlet->id,
            'opened_by_user_id' => (int) $user->id,
            'status' => 'open',
            'opening_cash' => 500000,
            'opened_at' => now(),
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/shift-close/run', ['outletId' => (int) $outlet->id])
            ->assertStatus(422);

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/shift-close/run', ['outletId' => (int) $outlet->id, 'confirm' => true])
            ->assertOk()
            ->assertJsonPath('data.preflight.checks.openPosSession', 1);
    }

    public function test_open_bill_block_policy_prevents_run(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Block Bills');
        DB::table('system_settings')->updateOrInsert(
            ['id' => 1],
            [
                'enable_split_bill' => true,
                'enable_multi_payment' => true,
                'confirm_before_payment' => true,
                'enable_qr_ordering' => true,
                'enable_call_cashier' => true,
                'enforce_stock_on_sale' => false,
                'stock_enforcement_mode' => 'deferred',
                'allow_negative_stock' => true,
                'shift_close_open_bill_policy' => 'block',
                'employee_self_service_enabled' => false,
                'updated_at' => now(),
                'created_at' => now(),
            ],
        );
        Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'BLOCK-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'completed',
            'payment_status' => 'unpaid',
            'total' => 5000,
            'subtotal' => 5000,
            'tax' => 0,
            'paid_total' => 0,
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/shift-close/run', ['outletId' => (int) $outlet->id, 'confirm' => true])
            ->assertStatus(422);
    }

    public function test_shift_close_run_emits_audit_events(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Audit Close');
        $this->setRevenuePostingMode(AccountingSetting::MODE_SHIFT_CLOSE, (int) $outlet->id);
        $this->seedShiftCloseAccounts();

        Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'AUDIT-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'completed',
            'payment_status' => 'paid',
            'paid_total' => 12000,
            'total' => 12000,
            'subtotal' => 12000,
            'tax' => 0,
            'is_posted' => false,
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/shift-close/run', [
                'outletId' => (int) $outlet->id,
                'confirm' => true,
                'actualCash' => 12000,
            ])
            ->assertOk();

        $this->assertTrue(
            PosEventLog::query()
                ->where('outlet_id', (int) $outlet->id)
                ->where('event_type', 'shift.financial_close_completed')
                ->where('entity_type', 'shift_close')
                ->exists()
        );
        $this->assertTrue(
            PosEventLog::query()
                ->where('event_type', 'cash.reconciliation_completed')
                ->exists()
        );
        $this->assertDatabaseHas('shift_close_runs', [
            'outlet_id' => (int) $outlet->id,
            'status' => ShiftCloseRun::STATUS_COMPLETED,
        ]);
    }

    public function test_readiness_endpoint_returns_widget_payload(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Readiness');
        $this->actingAs($user, 'api')
            ->getJson('/api/v1/shift-close/readiness?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['label', 'ready', 'severity', 'checks', 'lastClose'],
            ]);
    }

    private function seedShiftCloseAccounts(): void
    {
        DB::table('accounts')->insert([
            ['code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'cash_bank', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'inventory', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '4100', 'name' => 'Sales Revenue', 'type' => 'revenue', 'subtype' => 'revenue', 'category' => 'sales_revenue', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '5100', 'name' => 'COGS', 'type' => 'expense', 'subtype' => 'cogs', 'category' => 'cogs', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
