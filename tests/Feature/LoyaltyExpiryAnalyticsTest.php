<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyMemberLedger;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use App\Modules\LoyaltyEngine\Services\LoyaltyBalanceProjectionService;
use App\Modules\LoyaltyEngine\Services\LoyaltyLedgerService;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyExpiryAnalyticsTest extends TestCase
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

    public function test_analytics_includes_expired_counters(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $member = Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'M-EXP-AN',
            'full_name' => 'Expiry Analytics',
            'name' => 'Expiry Analytics',
            'phone' => '081299988877',
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);

        $program = LoyaltyProgram::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'EXP-AN',
            'name' => 'Expiry',
            'type' => LoyaltyProgram::TYPE_SPEND_BASED,
            'is_active' => true,
            'expiry_enabled' => true,
            'expiry_days' => 1,
        ]);

        $ledger = app(LoyaltyLedgerService::class);
        $projection = app(LoyaltyBalanceProjectionService::class);

        $earn = $ledger->createEarnFromOrder((int) $member->id, (int) $program->id, 1001, 100)['entry'];
        $earn->created_at = now()->subDays(2);
        $earn->save();
        $projection->applyLedgerEntry($earn);

        $expired1 = $ledger->createExpiredFromEarning((int) $member->id, (int) $earn->id, 60, (int) $program->id, 1);
        $projection->applyLedgerEntry($expired1);

        $earn2 = $ledger->createEarnFromOrder((int) $member->id, (int) $program->id, 1002, 40)['entry'];
        $earn2->created_at = now()->subDays(2);
        $earn2->save();
        $projection->applyLedgerEntry($earn2);

        $expired2 = $ledger->createExpiredFromEarning((int) $member->id, (int) $earn2->id, 40, (int) $program->id, 1);
        $projection->applyLedgerEntry($expired2);

        $this->getJson('/api/v1/loyalty-engine/analytics?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.expiredTransactions', 2)
            ->assertJsonPath('data.expiredPoints', 100);
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_expiry_analytics__'],
            ['description' => 'Members manager'],
        );
        $role->permissions()->sync(
            Permission::query()->where('code', 'members.manage')->pluck('id')->all(),
        );

        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        return $user;
    }

    private function createOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Expiry Analytics Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lexpa-'.uniqid(),
        ]);
    }
}
