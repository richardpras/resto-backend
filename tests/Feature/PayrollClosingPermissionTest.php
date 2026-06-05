<?php

namespace Tests\Feature;

use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\FinalizedPayrollRunFixture;
use Tests\TestCase;

class PayrollClosingPermissionTest extends TestCase
{
    use FinalizedPayrollRunFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    private function actingAsUserWithoutPayrollManage(): User
    {
        $this->seed(UserManagementPermissionsSeeder::class);

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_no_payroll__'],
            ['description' => 'Test fixture: no payroll permission'],
        );

        $payrollPerm = Permission::query()->where('code', 'payroll.manage')->first();
        $role->permissions()->sync(
            Permission::query()
                ->when($payrollPerm !== null, fn ($q) => $q->where('id', '!=', $payrollPerm->id))
                ->pluck('id')
                ->all(),
        );

        $user = User::factory()->create([
            'email' => 'no-payroll-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);

        Passport::actingAs($user);

        return $user;
    }

    public function test_closing_endpoints_require_payroll_manage(): void
    {
        $this->actingAsHrmApiAdministrator();
        [, , $run] = $this->seedFinalizedPayrollRun();
        $runId = (int) $run->id;

        $this->actingAsUserWithoutPayrollManage();

        $this->getJson('/api/v1/payroll-runs-v2/'.$runId.'/closing-summary')->assertStatus(403);
        $this->postJson('/api/v1/payroll-runs-v2/'.$runId.'/start-payment')->assertStatus(403);
        $this->getJson('/api/v1/payroll-runs-v2/'.$runId.'/audit')->assertStatus(403);
    }

    private function actingAsHrmApiAdministrator(): User
    {
        $this->seed(UserManagementPermissionsSeeder::class);

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_hrm_admin__'],
            ['description' => 'Test fixture: full access'],
        );
        $role->permissions()->sync(Permission::query()->pluck('id')->all());

        $user = User::factory()->create([
            'email' => 'hrm-admin-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        return $user;
    }
}
