<?php

namespace Tests\Feature;

use App\Models\Modules\UserManagement\Domain\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\OrganizationStructureTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class EmployeeUserAssignmentTest extends TestCase
{
    use OrganizationStructureTestFixtures;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_assign_and_remove_user_with_outlet_validation(): void
    {
        $admin = $this->actingAsOrganizationAdmin();
        $outletA = $this->createOrganizationOutlet('A');
        $outletB = $this->createOrganizationOutlet('B');
        $this->assignUserToOutlets($admin, [(int) $outletA->id]);

        $employee = Employee::query()->create([
            'outlet_id' => $outletA->id,
            'employee_no' => 'EMP-LINK-01',
            'full_name' => 'Linked Staff',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $linkedUser = User::factory()->create([
            'email' => 'linked-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $this->assignUserToOutlets($linkedUser, [(int) $outletA->id]);

        $foreignUser = User::factory()->create([
            'email' => 'foreign-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $this->assignUserToOutlets($foreignUser, [(int) $outletB->id]);

        $this->patchJson('/api/v1/employees/'.$employee->id.'/assign-user', [
            'userId' => (int) $foreignUser->id,
        ])->assertStatus(422);

        $this->patchJson('/api/v1/employees/'.$employee->id.'/assign-user', [
            'userId' => (int) $linkedUser->id,
        ])->assertOk()
            ->assertJsonPath('data.userId', (int) $linkedUser->id)
            ->assertJsonPath('data.linkedUser.email', $linkedUser->email);

        $this->patchJson('/api/v1/employees/'.$employee->id.'/remove-user')
            ->assertOk()
            ->assertJsonPath('data.userId', null);

        $employee->refresh();
        $this->assertNull($employee->user_id);
    }
}
