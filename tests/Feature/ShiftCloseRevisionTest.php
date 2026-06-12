<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\AccountingSetting;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\Modules\ShiftClose\Domain\ShiftCloseRun;
use App\Modules\ShiftClose\Services\ShiftCloseLockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class ShiftCloseRevisionTest extends TestCase
{
    use RefreshDatabase;
    use AccountingRemediationFixture;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_revision_audit_requirements_documented(): void
    {
        $this->assertFileExists(base_path('../docs/operations/SHIFT-CLOSE-ENGINE-01-REVISION-audit.md'));
    }

    public function test_preflight_returns_open_pos_sessions_detail(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('POS Session PF');
        PosSession::query()->create([
            'outlet_id' => (int) $outlet->id,
            'opened_by_user_id' => (int) $user->id,
            'status' => 'open',
            'opening_cash' => 500000,
            'opened_at' => now()->subHour(),
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/shift-close/preflight?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonPath('data.openPosSessions.count', 1)
            ->assertJsonPath('data.openPosSessions.severity', 'warning')
            ->assertJsonStructure(['data' => ['openPosSessions' => ['items' => [['id', 'cashierName', 'openedAt', 'openingCash']]]]]);
    }

    public function test_drawer_reconciliation_includes_full_formula_fields(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Drawer Formula');
        $this->actingAs($user, 'api')
            ->getJson('/api/v1/shift-close/preflight?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonStructure([
                'data' => ['drawerReconciliation' => [
                    'openingCash', 'cashSales', 'cashRefunds', 'cashExpenses', 'cashIn', 'cashOut', 'expected', 'limitations',
                ]],
            ]);
    }

    public function test_qr_breakdown_splits_statuses(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('QR Breakdown');
        $tableId = (int) DB::table('tables')->insertGetId([
            'outlet_id' => (int) $outlet->id,
            'name' => 'T-QR-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        QrOrderRequest::query()->create([
            'outlet_id' => (int) $outlet->id,
            'table_id' => $tableId,
            'request_code' => 'QR-PEND-'.uniqid(),
            'status' => 'pending',
            'expires_at' => now()->addHour(),
        ]);
        QrOrderRequest::query()->create([
            'outlet_id' => (int) $outlet->id,
            'table_id' => $tableId,
            'request_code' => 'QR-REV-'.uniqid(),
            'status' => 'under_review',
            'expires_at' => now()->addHour(),
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/shift-close/preflight?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonPath('data.qrOrders.pending', 1)
            ->assertJsonPath('data.qrOrders.underReview', 1);
    }

    public function test_lock_prevents_concurrent_close(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Lock');
        $date = app(ShiftCloseLockService::class)->shiftDate();
        ShiftCloseRun::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'shift_date' => $date,
            'status' => ShiftCloseRun::STATUS_RUNNING,
            'started_at' => now(),
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/shift-close/run', ['outletId' => (int) $outlet->id, 'confirm' => true])
            ->assertStatus(409)
            ->assertJsonPath('code', 'SHIFT_CLOSE_ALREADY_RUNNING');
    }

    public function test_run_snapshot_columns_persisted(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Snapshot');
        $this->setRevenuePostingMode(AccountingSetting::MODE_SHIFT_CLOSE, (int) $outlet->id);
        $this->seedAccounts();

        Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'SNAP-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'completed',
            'payment_status' => 'paid',
            'paid_total' => 25000,
            'total' => 25000,
            'subtotal' => 25000,
            'tax' => 0,
            'is_posted' => false,
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/shift-close/run', [
                'outletId' => (int) $outlet->id,
                'confirm' => true,
                'actualCash' => 25000,
            ])
            ->assertOk();

        $this->assertDatabaseHas('shift_close_runs', [
            'outlet_id' => (int) $outlet->id,
            'status' => ShiftCloseRun::STATUS_COMPLETED,
        ]);

        $run = ShiftCloseRun::query()->where('outlet_id', (int) $outlet->id)->latest('id')->first();
        $this->assertNotNull($run?->shift_date);
        $this->assertNotNull($run?->sales_amount);
        $this->assertNotNull($run?->expected_cash);
    }

    public function test_force_close_with_warnings(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Force');
        Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'FORCE-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Dine In',
            'status' => 'completed',
            'payment_status' => 'unpaid',
            'total' => 1000,
            'subtotal' => 1000,
            'tax' => 0,
            'paid_total' => 0,
        ]);

        $this->actingAs($user, 'api')
            ->postJson('/api/v1/shift-close/run', [
                'outletId' => (int) $outlet->id,
                'force' => true,
                'actualCash' => 0,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', ShiftCloseRun::STATUS_COMPLETED_WITH_WARNINGS);
    }

    public function test_report_endpoint_returns_structured_payload(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('Report');
        $run = ShiftCloseRun::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'shift_date' => now()->toDateString(),
            'status' => ShiftCloseRun::STATUS_COMPLETED,
            'sales_amount' => 100000,
            'cash_sales' => 80000,
            'non_cash_sales' => 20000,
            'opening_cash' => 50000,
            'expected_cash' => 130000,
            'actual_cash' => 128000,
            'cash_variance' => -2000,
            'started_at' => now()->subMinutes(5),
            'completed_at' => now(),
            'preflight_snapshot' => ['warnings' => []],
            'result_snapshot' => ['totalSales' => 100000],
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/shift-close/'.$run->id.'/report?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonPath('data.format', 'json')
            ->assertJsonPath('data.pdfAvailable', false)
            ->assertJsonPath('data.sales.total', 100000)
            ->assertJsonPath('data.cashVariance', -2000);
    }

    public function test_history_reads_snapshot_table(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('History');
        ShiftCloseRun::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'shift_date' => now()->toDateString(),
            'status' => ShiftCloseRun::STATUS_COMPLETED_WITH_WARNINGS,
            'open_bill_count' => 2,
            'cash_variance' => 500,
            'started_at' => now()->subHour(),
            'completed_at' => now(),
        ]);

        $this->actingAs($user, 'api')
            ->getJson('/api/v1/shift-close/history?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonPath('data.0.openBillCount', 2)
            ->assertJsonPath('data.0.status', ShiftCloseRun::STATUS_COMPLETED_WITH_WARNINGS);
    }

    protected function tearDown(): void
    {
        Cache::flush();
        parent::tearDown();
    }

    private function seedAccounts(): void
    {
        DB::table('accounts')->insert([
            ['code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'cash_bank', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'inventory', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '4100', 'name' => 'Sales Revenue', 'type' => 'revenue', 'subtype' => 'revenue', 'category' => 'sales_revenue', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '5100', 'name' => 'COGS', 'type' => 'expense', 'subtype' => 'cogs', 'category' => 'cogs', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
}
