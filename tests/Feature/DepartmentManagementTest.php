<?php

namespace Tests\Feature;

use App\Models\Modules\UserManagement\Domain\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\OrganizationStructureTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class DepartmentManagementTest extends TestCase
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

    public function test_department_crud_requires_users_manage(): void
    {
        $this->getJson('/api/v1/departments')->assertUnauthorized();
    }

    public function test_department_crud_and_outlet_isolation(): void
    {
        $admin = $this->actingAsOrganizationAdmin();
        $outletA = $this->createOrganizationOutlet('A');
        $outletB = $this->createOrganizationOutlet('B');
        $this->assignUserToOutlets($admin, [(int) $outletA->id]);

        $create = $this->postJson('/api/v1/departments', [
            'outletId' => (int) $outletA->id,
            'code' => 'OPS',
            'name' => 'Operations',
            'description' => 'Ops team',
        ])->assertCreated()
            ->assertJsonPath('data.code', 'OPS');

        $id = (int) $create->json('data.id');

        $this->getJson('/api/v1/departments?outletId='.(int) $outletA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->patchJson("/api/v1/departments/{$id}", [
            'name' => 'Operations Updated',
            'isActive' => false,
        ])->assertOk()
            ->assertJsonPath('data.name', 'Operations Updated')
            ->assertJsonPath('data.isActive', false);

        Department::query()->create([
            'outlet_id' => $outletB->id,
            'code' => 'HR-B',
            'name' => 'HR B',
            'is_active' => true,
        ]);

        $this->getJson('/api/v1/departments?outletId='.(int) $outletA->id)
            ->assertOk()
            ->assertJsonCount(1, 'data');

        $this->deleteJson("/api/v1/departments/{$id}")->assertOk();
        $this->assertDatabaseMissing('departments', ['id' => $id]);
    }
}
