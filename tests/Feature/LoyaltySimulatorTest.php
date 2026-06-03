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

class LoyaltySimulatorTest extends TestCase
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

    public function test_simulator_returns_spend_based_points_and_breakdown(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $programId = (int) $this->postJson('/api/v1/loyalty-programs', [
            'outletId' => $outlet->id,
            'code' => 'SIM-SPEND',
            'name' => 'Sim spend',
            'type' => 'spend_based',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/loyalty-programs/{$programId}/rules", [
            'config' => ['earnPerAmount' => 10000, 'pointsEarned' => 1],
        ])->assertCreated();

        $this->postJson('/api/v1/loyalty-programs/simulate', [
            'outletId' => $outlet->id,
            'programId' => $programId,
            'spendingAmount' => 250000,
            'simulationDate' => now()->toDateString(),
        ])
            ->assertOk()
            ->assertJsonPath('data.expectedPoints', 25)
            ->assertJsonPath('data.breakdown.0.step', 'spend_based')
            ->assertJsonCount(1, 'data.triggeredRules');
    }

    public function test_simulator_returns_zero_when_program_inactive(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $programId = (int) $this->postJson('/api/v1/loyalty-programs', [
            'outletId' => $outlet->id,
            'code' => 'SIM-OFF',
            'name' => 'Inactive',
            'type' => 'spend_based',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/loyalty-programs/{$programId}/rules", [
            'config' => ['earnPerAmount' => 10000, 'pointsEarned' => 1],
        ])->assertCreated();

        $this->patchJson("/api/v1/loyalty-programs/{$programId}/activation", [
            'isActive' => false,
        ])->assertOk();

        $this->postJson('/api/v1/loyalty-programs/simulate', [
            'outletId' => $outlet->id,
            'programId' => $programId,
            'spendingAmount' => 100000,
        ])
            ->assertOk()
            ->assertJsonPath('data.expectedPoints', 0)
            ->assertJsonPath('data.breakdown.0.step', 'activation');
    }

    public function test_simulator_visit_based_preview(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $programId = (int) $this->postJson('/api/v1/loyalty-programs', [
            'outletId' => $outlet->id,
            'code' => 'SIM-VISIT',
            'name' => 'Visit',
            'type' => 'visit_based',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/loyalty-programs/{$programId}/rules", [
            'ruleType' => 'visit_based',
            'config' => ['visit_threshold' => 10, 'points_awarded' => 100],
        ])->assertCreated();

        $this->postJson('/api/v1/loyalty-programs/simulate', [
            'outletId' => $outlet->id,
            'programId' => $programId,
            'visitCount' => 10,
        ])
            ->assertOk()
            ->assertJsonPath('data.expectedPoints', 100)
            ->assertJsonPath('data.breakdown.0.step', 'visit_based');
    }

    public function test_simulator_outlet_isolation_blocks_foreign_program(): void
    {
        $admin = $this->actingAsMembersManager();
        $allowed = $this->createOutlet('Allowed');
        $blocked = $this->createOutlet('Blocked');
        $this->assignUserToOutlets($admin, [(int) $allowed->id]);

        $foreignProgramId = (int) LoyaltyProgram::query()->create([
            'outlet_id' => $blocked->id,
            'code' => 'SIM-FOREIGN-'.uniqid(),
            'name' => 'Foreign',
            'type' => 'spend_based',
            'is_active' => true,
        ])->id;

        LoyaltyProgramRule::query()->create([
            'loyalty_program_id' => $foreignProgramId,
            'rule_type' => 'spend_based',
            'config' => ['earnPerAmount' => 10000, 'pointsEarned' => 1],
        ]);

        $this->postJson('/api/v1/loyalty-programs/simulate', [
            'outletId' => $allowed->id,
            'programId' => $foreignProgramId,
            'spendingAmount' => 50000,
        ])->assertStatus(422);
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_sim_admin__'],
            ['description' => 'Members manage for simulator tests'],
        );
        $role->permissions()->sync(
            Permission::query()->where('code', 'members.manage')->pluck('id')->all(),
        );
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        return $user;
    }

    private function createOutlet(string $suffix = ''): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Loyalty Sim Outlet '.$suffix.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lsim-'.uniqid(),
        ]);
    }
}
