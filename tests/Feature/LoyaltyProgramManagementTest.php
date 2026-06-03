<?php

namespace Tests\Feature;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgramRule;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyProgramManagementTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seed(UserManagementPermissionsSeeder::class);
    }

    public function test_program_crud_and_activation(): void
    {
        $admin = $this->actingAsMembersManager();
        $outletId = $this->createOutlet()->id;
        $this->assignUserToOutlets($admin, [(int) $outletId]);

        $create = $this->postJson('/api/v1/loyalty-programs', [
            'outletId' => $outletId,
            'code' => 'SPEND-OUTLET-A',
            'name' => 'Outlet spend program',
            'description' => 'Earn on spend',
            'type' => 'spend_based',
            'isActive' => true,
        ])->assertCreated();

        $programId = (int) $create->json('data.id');

        $this->patchJson("/api/v1/loyalty-programs/{$programId}", [
            'name' => 'Outlet spend program v2',
            'description' => 'Updated',
        ])->assertOk()->assertJsonPath('data.name', 'Outlet spend program v2');

        $this->patchJson("/api/v1/loyalty-programs/{$programId}/activation", [
            'isActive' => false,
        ])->assertOk()->assertJsonPath('data.isActive', false);

        $this->getJson('/api/v1/loyalty-programs?outletId='.$outletId.'&isActive=0')
            ->assertOk()
            ->assertJsonPath('data.0.id', (string) $programId);
    }

    public function test_rule_crud_for_program(): void
    {
        $admin = $this->actingAsMembersManager();
        $outletId = $this->createOutlet()->id;
        $this->assignUserToOutlets($admin, [(int) $outletId]);

        $programId = (int) $this->postJson('/api/v1/loyalty-programs', [
            'outletId' => $outletId,
            'code' => 'SPEND-RULES',
            'name' => 'Rules program',
            'type' => 'spend_based',
        ])->assertCreated()->json('data.id');

        $rule = $this->postJson("/api/v1/loyalty-programs/{$programId}/rules", [
            'ruleType' => 'spend_based',
            'config' => [
                'earnPerAmount' => 10000,
                'pointsEarned' => 1,
            ],
        ])->assertCreated();

        $ruleId = (int) $rule->json('data.id');

        $this->getJson("/api/v1/loyalty-programs/{$programId}/rules")
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->patchJson("/api/v1/loyalty-program-rules/{$ruleId}", [
            'config' => [
                'earnPerAmount' => 5000,
                'pointsEarned' => 2,
            ],
        ])->assertOk()->assertJsonPath('data.config.pointsEarned', 2);

        $this->deleteJson("/api/v1/loyalty-program-rules/{$ruleId}")->assertOk();
        $this->assertDatabaseMissing('loyalty_program_rules', ['id' => $ruleId]);
    }

    public function test_resolve_active_program_prefers_outlet_specific_spend_program(): void
    {
        $admin = $this->actingAsMembersManager();
        $outletA = $this->createOutlet('A');
        $outletB = $this->createOutlet('B');
        $this->assignUserToOutlets($admin, [(int) $outletA->id, (int) $outletB->id]);

        $globalId = (int) $this->postJson('/api/v1/loyalty-programs', [
            'code' => 'SPEND-GLOBAL',
            'name' => 'Global',
            'type' => 'spend_based',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/loyalty-programs/{$globalId}/rules", [
            'config' => ['earnPerAmount' => 10000, 'pointsEarned' => 1],
        ])->assertCreated();

        $outletProgramId = (int) $this->postJson('/api/v1/loyalty-programs', [
            'outletId' => $outletA->id,
            'code' => 'SPEND-A',
            'name' => 'Outlet A',
            'type' => 'spend_based',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/loyalty-programs/{$outletProgramId}/rules", [
            'config' => ['earnPerAmount' => 10000, 'pointsEarned' => 5],
        ])->assertCreated();

        $this->getJson('/api/v1/loyalty-programs/resolve-active?outletId='.$outletA->id.'&type=spend_based')
            ->assertOk()
            ->assertJsonPath('data.id', (string) $outletProgramId);
    }

    public function test_inactive_program_not_resolved(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $programId = (int) $this->postJson('/api/v1/loyalty-programs', [
            'outletId' => $outlet->id,
            'code' => 'SPEND-OFF',
            'name' => 'Off',
            'type' => 'spend_based',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/loyalty-programs/{$programId}/rules", [
            'config' => ['earnPerAmount' => 10000, 'pointsEarned' => 1],
        ])->assertCreated();

        $this->patchJson("/api/v1/loyalty-programs/{$programId}/activation", [
            'isActive' => false,
        ])->assertOk();

        $this->getJson('/api/v1/loyalty-programs/resolve-active?outletId='.$outlet->id.'&type=spend_based')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_outlet_isolation_blocks_foreign_outlet_program_access(): void
    {
        $admin = $this->actingAsMembersManager();
        $allowed = $this->createOutlet('Allowed');
        $blocked = $this->createOutlet('Blocked');
        $this->assignUserToOutlets($admin, [(int) $allowed->id]);

        $foreignProgramId = (int) LoyaltyProgram::query()->create([
            'outlet_id' => $blocked->id,
            'code' => 'FOREIGN-'.uniqid(),
            'name' => 'Foreign',
            'type' => 'spend_based',
            'is_active' => true,
        ])->id;

        $this->getJson('/api/v1/loyalty-programs/'.$foreignProgramId)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId']);
    }

    public function test_rule_type_must_match_program_type(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $programId = (int) $this->postJson('/api/v1/loyalty-programs', [
            'outletId' => $outlet->id,
            'code' => 'SPEND-MISMATCH',
            'name' => 'Spend',
            'type' => 'spend_based',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/loyalty-programs/{$programId}/rules", [
            'ruleType' => 'period_spending',
            'config' => ['period' => 'monthly', 'minimum_spend' => 0, 'reward_percent' => 2],
        ])->assertStatus(422);
    }

    public function test_spend_earning_still_works_after_management_api_setup(): void
    {
        $cashier = $this->actingAsPosCashier();
        $outletId = (int) $this->createOutlet()->id;
        $cashier->outlets()->sync([(int) $outletId]);

        $member = \App\Models\Member::query()->create([
            'outlet_id' => $outletId,
            'member_no' => 'MEM-API',
            'full_name' => 'Earn Test',
            'name' => 'Earn Test',
            'phone' => '081299988877',
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);

        $programId = (int) $this->postJson('/api/v1/loyalty-programs', [
            'outletId' => $outletId,
            'code' => 'SPEND-EARN-API',
            'name' => 'API Earn',
            'type' => 'spend_based',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/loyalty-programs/{$programId}/rules", [
            'config' => ['earnPerAmount' => 10000, 'pointsEarned' => 1],
        ])->assertCreated();

        $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outletId,
            'code' => 'LOY-MGMT-EARN',
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'completed',
            'paymentStatus' => 'paid',
            'memberId' => $member->id,
            'items' => [['id' => '1', 'name' => 'Item', 'qty' => 1, 'price' => 50000]],
            'subtotal' => 50000,
            'tax' => 0,
            'total' => 50000,
            'payments' => [['method' => 'cash', 'amount' => 50000]],
        ])->assertCreated();

        $this->assertDatabaseHas('member_loyalty_balances', [
            'member_id' => $member->id,
            'current_points' => 5,
        ]);
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_program_admin__'],
            ['description' => 'Members manage for loyalty program tests'],
        );
        $role->permissions()->sync(
            Permission::query()->where('code', 'members.manage')->pluck('id')->all(),
        );
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        return $user;
    }

    private function actingAsPosCashier(): User
    {
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_mgmt_cashier__'],
            ['description' => 'POS cashier'],
        );
        $role->permissions()->sync(
            Permission::query()->whereIn('code', ['pos.use', 'members.manage'])->pluck('id')->all(),
        );
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        return $user;
    }

    private function createOutlet(string $suffix = ''): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Loyalty Mgmt Outlet '.$suffix.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lpm-'.uniqid(),
        ]);
    }
}
