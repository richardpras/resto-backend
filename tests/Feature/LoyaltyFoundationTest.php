<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\TestCase;

class LoyaltyFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seed(UserManagementPermissionsSeeder::class);
    }

    public function test_cashier_can_create_search_and_attach_member_to_order(): void
    {
        $user = $this->actingAsPosCashier();
        $outletId = $this->seedOutletFor($user);

        $create = $this->postJson('/api/v1/members/quick', [
            'outletId' => $outletId,
            'fullName' => 'Budi Santoso',
            'phone' => '081234567890',
        ])->assertCreated();

        $memberId = (int) $create->json('data.id');
        $this->assertDatabaseHas('members', [
            'id' => $memberId,
            'outlet_id' => $outletId,
            'phone' => '081234567890',
        ]);

        $this->getJson('/api/v1/members/search?outletId='.$outletId.'&q=Budi')
            ->assertOk()
            ->assertJsonPath('data.0.fullName', 'Budi Santoso');

        $order = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => 'POS-MEMBER-1',
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'memberId' => $memberId,
            'items' => [
                ['id' => '101', 'name' => 'Nasi Goreng', 'qty' => 1, 'price' => 30000],
            ],
            'subtotal' => 30000,
            'tax' => 3000,
            'total' => 33000,
            'payments' => [],
        ])->assertCreated();

        $orderId = (int) $order->json('data.id');
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'member_id' => $memberId,
        ]);

        $this->patchJson("/api/v1/orders/{$orderId}/member", [
            'memberId' => null,
        ])->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'member_id' => null,
        ]);

        $this->patchJson("/api/v1/orders/{$orderId}/member", [
            'memberId' => $memberId,
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [
                ['method' => 'cash', 'amount' => 33000],
            ],
        ])->assertOk();

        $this->assertDatabaseHas('member_transactions', [
            'member_id' => $memberId,
            'order_id' => $orderId,
            'total_amount' => 33000,
        ]);

        $this->patchJson("/api/v1/orders/{$orderId}/member", [
            'memberId' => $memberId,
        ])->assertStatus(422);

        $this->getJson("/api/v1/members/{$memberId}/profile?outletId={$outletId}")
            ->assertOk()
            ->assertJsonPath('data.stats.totalVisits', 1)
            ->assertJsonPath('data.stats.totalSpending', 33000);
    }

    public function test_member_profile_returns_transaction_history(): void
    {
        $user = $this->actingAsPosCashier();
        $outletId = $this->seedOutletFor($user);

        $member = Member::query()->create([
            'outlet_id' => $outletId,
            'member_no' => 'MEM-TEST-1',
            'full_name' => 'Ani',
            'name' => 'Ani',
            'phone' => '081111111111',
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);

        $this->getJson('/api/v1/members/'.$member->id.'/profile?outletId='.$outletId)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'member',
                    'stats' => ['totalVisits', 'totalSpending', 'lastVisit'],
                    'transactions',
                ],
            ]);
    }

    private function actingAsPosCashier(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_cashier__'],
            ['description' => 'POS cashier for loyalty foundation tests'],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('code', ['pos.use'])->pluck('id')->all(),
        );

        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        return $user;
    }

    private function seedOutletFor(User $user): int
    {
        $outlet = Outlet::query()->create([
            'name' => 'Loyalty Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'loy-'.uniqid(),
        ]);
        $user->outlets()->attach([(int) $outlet->id]);

        return (int) $outlet->id;
    }
}
