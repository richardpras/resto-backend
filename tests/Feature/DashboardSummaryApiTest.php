<?php

namespace Tests\Feature;

use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class DashboardSummaryApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        config(['cache.default' => 'array']);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_dashboard_summary_enforces_outlet_scope_and_returns_cross_outlet_best_sellers(): void
    {
        $user = $this->actingAsScopedPosUser();
        $outletA = $this->createOutlet('DSA');
        $outletB = $this->createOutlet('DSB');
        $this->assignUserToOutlets($user, [(int) $outletA->id, (int) $outletB->id]);

        $this->createOrderWithItem((int) $outletA->id, 'A-ORDER', 'Nasi Goreng', 2, 60000);
        $this->createOrderWithItem((int) $outletB->id, 'B-ORDER', 'Ayam Bakar', 5, 175000);

        $response = $this->getJson('/api/v1/dashboard/summary?outletId='.(int) $outletA->id);
        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.kpis.orderCountToday', 1);
        $response->assertJsonPath('data.kpis.revenueToday', 60000);
        $response->assertJsonPath('data.monitoring.activePosSessions', 0);
        $response->assertJsonPath('data.bestSellerOtherOutlets.0.name', 'Ayam Bakar');
        $response->assertJsonPath('data.bestSellerOtherOutlets.0.outletName', $outletB->name);
    }

    public function test_dashboard_summary_requires_authenticated_user(): void
    {
        $this->getJson('/api/v1/dashboard/summary')->assertStatus(401);
    }

    public function test_dashboard_summary_uses_cache_for_same_scope(): void
    {
        config(['monitoring.dashboard_summary_cache_seconds' => 60]);
        $user = $this->actingAsScopedPosUser();
        $outletA = $this->createOutlet('DCA');
        $this->assignUserToOutlets($user, [(int) $outletA->id]);

        $this->createOrderWithItem((int) $outletA->id, 'CACHED-1', 'Kopi', 1, 25000);

        $first = $this->getJson('/api/v1/dashboard/summary?outletId='.(int) $outletA->id);
        $first->assertOk();
        $first->assertJsonPath('data.kpis.orderCountToday', 1);

        $this->createOrderWithItem((int) $outletA->id, 'CACHED-2', 'Teh', 1, 30000);

        $second = $this->getJson('/api/v1/dashboard/summary?outletId='.(int) $outletA->id);
        $second->assertOk();
        $second->assertJsonPath('data.kpis.orderCountToday', 1);
    }

    public function test_dashboard_summary_filters_by_start_and_end_date(): void
    {
        $user = $this->actingAsScopedPosUser();
        $outletA = $this->createOutlet('DSD');
        $this->assignUserToOutlets($user, [(int) $outletA->id]);

        $this->createOrderWithItem((int) $outletA->id, 'RANGE-TODAY', 'Today Item', 1, 40000);
        $this->createOrderWithItem(
            (int) $outletA->id,
            'RANGE-YESTERDAY',
            'Yesterday Item',
            1,
            50000,
            now()->subDay(),
        );

        $today = now()->toDateString();
        $response = $this->getJson('/api/v1/dashboard/summary?outletId='.(int) $outletA->id.'&startDate='.$today.'&endDate='.$today);
        $response->assertOk();
        $response->assertJsonPath('data.kpis.orderCountToday', 1);
        $response->assertJsonPath('data.kpis.revenueToday', 40000);

        $yesterday = now()->subDay()->toDateString();
        $rangeResponse = $this->getJson(
            '/api/v1/dashboard/summary?outletId='.(int) $outletA->id.'&startDate='.$yesterday.'&endDate='.$today,
        );
        $rangeResponse->assertOk();
        $rangeResponse->assertJsonPath('data.kpis.orderCountToday', 2);
        $rangeResponse->assertJsonPath('data.kpis.revenueToday', 90000);
    }

    private function createOutlet(string $prefix): Outlet
    {
        return Outlet::query()->create([
            'name' => $prefix.' Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => strtolower($prefix).'-'.uniqid(),
        ]);
    }

    private function actingAsScopedPosUser(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_dashboard_pos_scoped__'],
            ['description' => 'Test fixture: dashboard pos scoped access'],
        );
        $permissionIds = Permission::query()
            ->whereNotIn('name', ['outlets.view_all', 'dashboard.view_all_outlets'])
            ->pluck('id')
            ->all();
        $role->permissions()->sync($permissionIds);

        $user = User::factory()->create([
            'email' => 'dashboard-scoped-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        return $user;
    }

    private function createOrderWithItem(int $outletId, string $code, string $itemName, int $qty, int $lineTotal, ?\DateTimeInterface $createdAt = null): void
    {
        $timestamp = $createdAt ?? now();
        $orderId = (int) DB::table('orders')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'pos_session_id' => null,
            'code' => $code.'-'.uniqid(),
            'source' => 'pos',
            'order_channel' => 'dine_in',
            'service_mode' => 'dine_in',
            'order_type' => 'Dine In',
            'status' => 'confirmed',
            'payment_status' => 'paid',
            'kitchen_status' => 'ready',
            'subtotal' => $lineTotal,
            'tax' => 0,
            'total' => $lineTotal,
            'paid_total' => $lineTotal,
            'balance_due' => 0,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('order_items')->insert([
            'order_id' => $orderId,
            'item_id' => strtolower(str_replace(' ', '-', $itemName)).'-'.uniqid(),
            'name' => $itemName,
            'emoji' => null,
            'qty' => $qty,
            'price' => (int) floor($lineTotal / max(1, $qty)),
            'line_total' => $lineTotal,
            'notes' => null,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }
}

