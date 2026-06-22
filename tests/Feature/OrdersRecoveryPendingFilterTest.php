<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class OrdersRecoveryPendingFilterTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seed(\Database\Seeders\UserManagementPermissionsSeeder::class);
    }

    public function test_has_recovery_pending_filter_returns_only_matching_orders(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Filter Outlet');
        $this->assignUserToOutlets($user, [$outlet->id]);

        $pendingOrderId = $this->seedOrderWithRecoveryStatus($outlet->id, 'PEND-1', 'recovery_pending');
        $this->seedOrderWithRecoveryStatus($outlet->id, 'OK-1', null);

        $response = $this->getJson('/api/v1/orders?hasRecoveryPending=1&outletId='.$outlet->id);
        $response->assertOk();
        $codes = collect($response->json('data'))->pluck('code')->all();
        self::assertContains('PEND-1', $codes);
        self::assertNotContains('OK-1', $codes);

        $first = collect($response->json('data'))->firstWhere('code', 'PEND-1');
        self::assertSame(1, (int) ($first['pendingRecoveryCount'] ?? 0));
    }

    public function test_recovery_pending_count_endpoint(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('Count Outlet');
        $this->assignUserToOutlets($user, [$outlet->id]);

        $this->seedOrderWithRecoveryStatus($outlet->id, 'CNT-1', 'recovery_pending');

        $response = $this->getJson('/api/v1/orders/recovery-pending-count?outletId='.$outlet->id);
        $response->assertOk();
        self::assertSame(1, (int) $response->json('data.count'));
    }

    private function createOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'orf-'.uniqid(),
        ]);
    }

    private function seedOrderWithRecoveryStatus(int $outletId, string $code, ?string $recoveryStatus): int
    {
        $orderId = DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'code' => $code,
            'source' => 'pos',
            'order_type' => 'Takeaway',
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'paid_total' => 10000,
            'balance_due' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'item_id' => '101',
            'name' => 'Nasi',
            'qty' => 1,
            'price' => 10000,
            'line_total' => 10000,
            'recovery_status' => $recoveryStatus,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $orderId;
    }
}
