<?php

namespace Tests\Feature;

use App\Models\Modules\UserManagement\Domain\Department;
use App\Models\Modules\UserManagement\Domain\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\OrganizationStructureTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class PositionManagementTest extends TestCase
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

    public function test_position_crud_and_validation(): void
    {
        $admin = $this->actingAsOrganizationAdmin();
        $outlet = $this->createOrganizationOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $department = Department::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'SVC',
            'name' => 'Service',
            'is_active' => true,
        ]);

        $create = $this->postJson('/api/v1/positions', [
            'outletId' => (int) $outlet->id,
            'departmentId' => (int) $department->id,
            'code' => 'WAITER',
            'name' => 'Waiter',
            'sortOrder' => 10,
        ])->assertCreated()
            ->assertJsonPath('data.code', 'WAITER');

        $id = (int) $create->json('data.id');

        $this->getJson('/api/v1/positions?outletId='.(int) $outlet->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $inactive = Position::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'OLD',
            'name' => 'Old Role',
            'is_active' => false,
        ]);

        $this->postJson('/api/v1/employees', [
            'outletId' => (int) $outlet->id,
            'employeeNo' => 'EMP-POS-01',
            'fullName' => 'Test Staff',
            'positionId' => (int) $inactive->id,
        ])->assertStatus(422);

        $this->patchJson("/api/v1/positions/{$id}", [
            'name' => 'Senior Waiter',
            'isActive' => false,
        ])->assertOk();

        $this->deleteJson("/api/v1/positions/{$id}")->assertOk();
    }
}
