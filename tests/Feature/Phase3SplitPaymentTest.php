<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class Phase3SplitPaymentTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_cannot_overpay_order(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, 'P3-OVERPAY');

        $response = $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [
                ['method' => 'cash', 'amount' => 12000],
            ],
        ]);

        $response->assertUnprocessable();
    }

    public function test_cannot_over_allocate_split_items(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, 'P3-OVERALLOC');

        $order = DB::table('orders')->where('id', $orderId)->first();
        $items = DB::table('order_items')->where('order_id', $orderId)->orderBy('id')->get();

        $split = $this->postJson("/api/v1/orders/{$orderId}/splits", [
            'splitType' => 'by_item',
            'label' => 'Person A',
            'items' => [
                [
                    'orderItemId' => (int) $items[0]->id,
                    'qty' => 3,
                    'amount' => (float) $order->total,
                ],
            ],
        ]);

        $split->assertUnprocessable();
    }

    public function test_partial_payment_recomputes_status(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, 'P3-PARTIAL');

        $pay = $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [
                ['method' => 'cash', 'amount' => 5000],
            ],
        ]);

        $pay->assertOk();
        $pay->assertJsonPath('data.paymentStatus', 'partial');
    }

    public function test_full_payment_recomputes_paid_status(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, 'P3-PAID');

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [
                ['method' => 'cash', 'amount' => 5000],
            ],
        ])->assertOk();

        $final = $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [
                ['method' => 'transfer', 'amount' => 6000],
            ],
        ]);

        $final->assertOk();
        $final->assertJsonPath('data.paymentStatus', 'paid');
    }

    public function test_mixed_payment_methods_work(): void
    {
        [, $outlet] = $this->actAsAdminWithOutlet();
        $orderId = $this->createConfirmedOrder($outlet->id, 'P3-MIXED');

        $pay = $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [
                ['method' => 'cash', 'amount' => 2000],
                ['method' => 'transfer', 'amount' => 3000],
                ['method' => 'ewallet', 'amount' => 1000],
            ],
        ]);
        $pay->assertOk();
        $pay->assertJsonCount(3, 'data.payments');
    }

    public function test_non_owner_cannot_access_other_outlet_split_and_payment_data(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $allowed = $this->createOutlet('P3 Allowed Outlet');
        $forbidden = $this->createOutlet('P3 Forbidden Outlet');
        $this->assignUserToOutlets($user, [$allowed->id]);

        $forbiddenOrderId = (int) DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $forbidden->id,
            'code' => 'P3-FORBIDDEN',
            'source' => 'pos',
            'order_type' => 'Takeaway',
            'status' => 'confirmed',
            'payment_status' => 'unpaid',
            'subtotal' => 10000,
            'tax' => 1000,
            'total' => 11000,
            'paid_total' => 0,
            'balance_due' => 11000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $forbiddenOrderId,
            'item_id' => 'x-1',
            'name' => 'Forbidden Item',
            'qty' => 1,
            'price' => 11000,
            'line_total' => 11000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $forbiddenItemId = (int) DB::table('order_items')->where('order_id', $forbiddenOrderId)->value('id');
        $this->postJson("/api/v1/orders/{$forbiddenOrderId}/splits", [
            'splitType' => 'by_item',
            'label' => 'Forbidden Split',
            'items' => [[
                'orderItemId' => $forbiddenItemId,
                'qty' => 1,
                'amount' => 1000,
            ]],
        ])->assertNotFound();

        $this->postJson("/api/v1/orders/{$forbiddenOrderId}/payments", [
            'payments' => [
                ['method' => 'cash', 'amount' => 1000],
            ],
        ])->assertNotFound();

        $this->getJson("/api/v1/orders/{$forbiddenOrderId}/payments")->assertNotFound();
    }

    /** @return array{0: \App\Models\User, 1: \App\Models\Modules\Settings\Domain\Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutlet('P3 Outlet');
        $this->assignUserToOutlets($user, [$outlet->id]);

        return [$user, $outlet];
    }

    private function createOutlet(string $name): Outlet
    {
        return Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p3-'.uniqid(),
        ]);
    }

    private function createConfirmedOrder(int $outletId, string $code): int
    {
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outletId,
            'name' => 'P3-T-'.uniqid(),
            'capacity' => 4,
            'status' => 'active',
        ]);

        $session = PosSession::query()->create([
            'outlet_id' => $outletId,
            'opened_by_user_id' => auth()->id(),
            'status' => 'open',
            'opening_cash' => 100000,
            'opened_at' => now(),
        ]);

        $create = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => $code,
            'source' => 'pos',
            'orderType' => 'Dine In',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'serviceMode' => 'dine_in',
            'orderChannel' => 'dine_in',
            'posSessionId' => (int) $session->id,
            'tableId' => (int) $table->id,
            'items' => [
                ['id' => '101', 'name' => 'Item A', 'qty' => 1, 'price' => 5000],
                ['id' => '102', 'name' => 'Item B', 'qty' => 1, 'price' => 5000],
            ],
            'subtotal' => 10000,
            'tax' => 1000,
            'total' => 11000,
            'payments' => [],
        ]);
        $create->assertCreated();

        return (int) $create->json('data.id');
    }
}
