<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class OpenBillAggregationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_aggregates_multiple_qr_orders_on_same_table(): void
    {
        [$outlet, $table] = $this->seedOutletAndTable();
        $this->seedOrder($outlet->id, $table->id, 'QR-A', 'qr', 100000, 10000, 'unpaid', 'confirmed');
        $this->seedOrder($outlet->id, $table->id, 'QR-B', 'qr', 50000, 5000, 'unpaid', 'confirmed');

        $response = $this->getJson('/api/v1/open-bills/table?outletId='.$outlet->id.'&tableId='.$table->id)->assertOk();
        $response->assertJsonPath('data.orderCount', 2);
        $response->assertJsonPath('data.remainingPayable', 165000);
    }

    public function test_aggregates_mixed_pos_and_qr_orders(): void
    {
        [$outlet, $table] = $this->seedOutletAndTable();
        $this->seedOrder($outlet->id, $table->id, 'POS-A', 'pos', 80000, 8000, 'unpaid', 'confirmed');
        $this->seedOrder($outlet->id, $table->id, 'QR-A', 'qr', 60000, 6000, 'unpaid', 'confirmed');

        $response = $this->getJson('/api/v1/open-bills/table?outletId='.$outlet->id.'&tableId='.$table->id)->assertOk();
        $response->assertJsonPath('data.orderCount', 2);
        $response->assertJsonPath('data.remainingPayable', 154000);
    }

    public function test_partial_payment_order_remains_with_remaining_payable(): void
    {
        [$outlet, $table] = $this->seedOutletAndTable();
        $order = $this->seedOrder($outlet->id, $table->id, 'POS-PART', 'pos', 100000, 10000, 'unpaid', 'confirmed');

        $this->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'payments' => [
                ['method' => 'cash', 'amount' => 40000, 'paidAt' => now()->toISOString()],
            ],
        ])->assertOk();

        $response = $this->getJson('/api/v1/open-bills/table?outletId='.$outlet->id.'&tableId='.$table->id)->assertOk();
        $response->assertJsonPath('data.orderCount', 1);
        $response->assertJsonPath('data.remainingPayable', 70000);
    }

    public function test_full_settlement_removes_order_from_open_bill_projection(): void
    {
        [$outlet, $table] = $this->seedOutletAndTable();
        $order = $this->seedOrder($outlet->id, $table->id, 'POS-FULL', 'pos', 50000, 5000, 'unpaid', 'confirmed');

        $this->postJson('/api/v1/orders/'.$order->id.'/payments', [
            'payments' => [
                ['method' => 'cash', 'amount' => 55000, 'paidAt' => now()->toISOString()],
            ],
        ])->assertOk();

        $response = $this->getJson('/api/v1/open-bills/table?outletId='.$outlet->id.'&tableId='.$table->id)->assertOk();
        $response->assertJsonPath('data.orderCount', 0);
        $response->assertJsonPath('data.remainingPayable', 0);
    }

    public function test_open_bill_recalculation_after_sequential_settlements(): void
    {
        [$outlet, $table] = $this->seedOutletAndTable();
        $orderA = $this->seedOrder($outlet->id, $table->id, 'POS-A', 'pos', 100000, 10000, 'unpaid', 'confirmed');
        $orderB = $this->seedOrder($outlet->id, $table->id, 'QR-B', 'qr', 20000, 2000, 'unpaid', 'confirmed');

        $first = $this->getJson('/api/v1/open-bills/table?outletId='.$outlet->id.'&tableId='.$table->id)->assertOk();
        $first->assertJsonPath('data.remainingPayable', 132000);

        $this->postJson('/api/v1/orders/'.$orderA->id.'/payments', [
            'payments' => [
                ['method' => 'cash', 'amount' => 50000, 'paidAt' => now()->toISOString()],
            ],
        ])->assertOk();
        $second = $this->getJson('/api/v1/open-bills/table?outletId='.$outlet->id.'&tableId='.$table->id)->assertOk();
        $second->assertJsonPath('data.remainingPayable', 82000);

        $this->postJson('/api/v1/orders/'.$orderA->id.'/payments', [
            'payments' => [
                ['method' => 'cash', 'amount' => 60000, 'paidAt' => now()->toISOString()],
            ],
        ])->assertOk();
        $third = $this->getJson('/api/v1/open-bills/table?outletId='.$outlet->id.'&tableId='.$table->id)->assertOk();
        $third->assertJsonPath('data.remainingPayable', 22000);

        $this->postJson('/api/v1/orders/'.$orderB->id.'/payments', [
            'payments' => [
                ['method' => 'cash', 'amount' => 22000, 'paidAt' => now()->toISOString()],
            ],
        ])->assertOk();
        $final = $this->getJson('/api/v1/open-bills/table?outletId='.$outlet->id.'&tableId='.$table->id)->assertOk();
        $final->assertJsonPath('data.remainingPayable', 0);
        $final->assertJsonPath('data.orderCount', 0);
    }

    private function seedOutletAndTable(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'Open Bill Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'open-bill-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'TB-12',
            'capacity' => 4,
            'status' => 'active',
            'active' => true,
        ]);

        return [$outlet, $table];
    }

    private function seedOrder(
        int $outletId,
        int $tableId,
        string $code,
        string $source,
        int $subtotal,
        int $tax,
        string $paymentStatus,
        string $status
    ): Order {
        $total = $subtotal + $tax;

        return Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'code' => $code.'-'.uniqid(),
            'source' => $source,
            'order_type' => 'Dine-in',
            'status' => $status,
            'payment_status' => $paymentStatus,
            'subtotal' => $subtotal,
            'tax' => $tax,
            'total' => $total,
            'paid_total' => 0,
            'balance_due' => $total,
            'table_id' => $tableId,
            'table_name' => 'TB-12',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
