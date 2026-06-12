<?php

namespace Tests\Feature;

use App\Models\Modules\Inventory\Domain\InventoryConsumptionQueue;
use App\Models\Modules\Loyalty\Domain\LoyaltyMembershipTier;
use App\Models\Modules\Menu\Domain\MenuEngineeringSnapshot;
use App\Models\Modules\Notifications\Domain\UserNotification;
use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\ShiftClose\Domain\ShiftCloseRun;
use Database\Seeders\Demo\DemoProductionDemoPatch03Seeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\DemoSeederTestSetup;
use Tests\TestCase;

class DemoSeeder03Test extends TestCase
{
    use DemoSeederTestSetup;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpDemoSeederEnvironment();
        $this->seed(DemoProductionDemoPatch03Seeder::class);
    }

    public function test_all_qr_lifecycle_states_exist_for_sunset(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $codes = [
            'DEMO-SUNSET-QRO-PENDING',
            'DEMO-SUNSET-QRO-UNDER-REVIEW',
            'DEMO-SUNSET-QRO-ADJUSTED',
            'DEMO-SUNSET-QRO-CONFIRMED',
            'DEMO-SUNSET-QRO-COOKING',
            'DEMO-SUNSET-QRO-READY',
            'DEMO-SUNSET-QRO-SERVED',
            'DEMO-SUNSET-QRO-COMPLETED',
            'DEMO-SUNSET-QRO-CANCELLED',
            'DEMO-SUNSET-QRO-ADDITIONAL',
        ];

        foreach ($codes as $code) {
            $this->assertDatabaseHas('qr_order_requests', [
                'request_code' => $code,
                'outlet_id' => $outlet->id,
            ]);
        }
    }

    public function test_mountain_lifecycle_codes_exist(): void
    {
        $this->assertDatabaseHas('qr_order_requests', ['request_code' => 'DEMO-MOUNTAIN-QRO-COOKING']);
        $this->assertDatabaseHas('qr_order_requests', ['request_code' => 'DEMO-MOUNTAIN-QRO-ADJUSTED']);
    }

    public function test_adjusted_order_has_review_draft(): void
    {
        $request = QrOrderRequest::query()->where('request_code', 'DEMO-SUNSET-QRO-ADJUSTED')->firstOrFail();
        $this->assertIsArray($request->review_draft);
        $this->assertNotEmpty($request->review_draft['adjustments'] ?? []);
    }

    public function test_public_lookup_for_adjusted_order(): void
    {
        $this->getJson('/api/v1/public/qr-orders/DEMO-SUNSET-QRO-ADJUSTED')
            ->assertOk()
            ->assertJsonPath('data.customerStatus', 'adjusted')
            ->assertJsonStructure(['data' => ['timeline', 'items', 'total']]);
    }

    public function test_shift_close_runs_exist_with_snapshots(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        foreach ([
            'DEMO-SUNSET-SHIFT-CLOSE-COMPLETED',
            'DEMO-SUNSET-SHIFT-CLOSE-WARNING',
            'DEMO-SUNSET-SHIFT-CLOSE-FAILED',
            'DEMO-SUNSET-SHIFT-CLOSE-RUNNING',
        ] as $ref) {
            $run = ShiftCloseRun::query()
                ->where('outlet_id', $outlet->id)
                ->where('metadata->demoReference', $ref)
                ->first();
            $this->assertNotNull($run, "Missing shift close run {$ref}");
            $this->assertNotNull($run->sales_amount);
            $this->assertNotNull($run->preflight_snapshot);
        }
    }

    public function test_consumption_queue_states_exist(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        foreach (['pending', 'processed', 'review_required', 'failed'] as $status) {
            $this->assertTrue(
                InventoryConsumptionQueue::query()
                    ->where('outlet_id', $outlet->id)
                    ->where('status', $status)
                    ->exists(),
                "Missing consumption queue status {$status}",
            );
        }
    }

    public function test_purchase_invoices_exist(): void
    {
        if (! Schema::hasTable('purchase_invoices')) {
            $this->markTestSkipped('purchase_invoices table not available');
        }

        foreach (['OUTSTANDING', 'PARTIAL', 'PAID', 'OVERDUE'] as $suffix) {
            $this->assertTrue(
                PurchaseInvoice::query()->where('number', "DEMO-SUNSET-PI-{$suffix}")->exists(),
            );
        }
    }

    public function test_menu_engineering_snapshots_exist(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $this->assertGreaterThan(
            0,
            MenuEngineeringSnapshot::query()->where('outlet_id', $outlet->id)->count(),
        );
    }

    public function test_loyalty_tiers_exist(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        foreach (['BRONZE', 'SILVER', 'GOLD'] as $code) {
            $this->assertTrue(
                LoyaltyMembershipTier::query()->where('outlet_id', $outlet->id)->where('code', $code)->exists(),
            );
        }
    }

    public function test_seeder_re_runs_without_duplicates(): void
    {
        $qrCount = QrOrderRequest::query()->where('request_code', 'like', 'DEMO-%-QRO-%')->count();
        $shiftCount = ShiftCloseRun::query()->where('metadata->demoPatch', '03')->count();

        $this->seed(DemoProductionDemoPatch03Seeder::class);

        $this->assertSame($qrCount, QrOrderRequest::query()->where('request_code', 'like', 'DEMO-%-QRO-%')->count());
        $this->assertSame($shiftCount, ShiftCloseRun::query()->where('metadata->demoPatch', '03')->count());
    }

    public function test_direct_pos_and_qr_linked_orders_exist(): void
    {
        $this->assertDatabaseHas('orders', ['code' => 'DEMO-SUNSET-POS-DIRECT-OPEN', 'source_type' => 'direct_pos']);
        $this->assertDatabaseHas('orders', ['code' => 'DEMO-SUNSET-POS-CONFIRMED', 'source_type' => 'qr_order']);
    }

    public function test_audit_and_notifications_include_new_flows(): void
    {
        $outlet = Outlet::query()->where('code', 'DEMO-SUNSET')->firstOrFail();
        $this->assertTrue(
            PosEventLog::query()->where('outlet_id', $outlet->id)->where('event_type', 'customer_order.adjusted')->exists(),
        );
        $this->assertTrue(
            UserNotification::query()->where('outlet_id', $outlet->id)->where('source_type', 'customer_call_cashier')->exists(),
        );
    }
}
