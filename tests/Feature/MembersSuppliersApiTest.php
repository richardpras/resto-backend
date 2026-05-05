<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class MembersSuppliersApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_members_crud_and_status_toggle(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $this->getJson('/api/v1/members')->assertOk()->assertJsonStructure(['data']);

        $create = $this->postJson('/api/v1/members', [
            'name' => 'Test Member',
            'phone' => '081111112222',
            'email' => 'test@example.com',
            'status' => 'active',
        ])->assertCreated()->assertJsonPath('data.name', 'Test Member');

        $id = $create->json('data.id');

        $this->patchJson("/api/v1/members/{$id}", [
            'points' => 100,
        ])->assertOk()->assertJsonPath('data.points', 100);

        $this->patchJson("/api/v1/members/{$id}/status")->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->deleteJson("/api/v1/members/{$id}")->assertOk();

        $this->assertDatabaseMissing('members', ['id' => $id]);
    }

    public function test_suppliers_crud_and_status_toggle(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $this->getJson('/api/v1/suppliers')->assertOk()->assertJsonStructure(['data']);

        $create = $this->postJson('/api/v1/suppliers', [
            'name' => 'Test Supplier',
            'contact' => '082222223333',
            'email' => 'supplier@example.com',
            'address' => 'Jl. Test',
            'status' => 'active',
        ])->assertCreated()->assertJsonPath('data.name', 'Test Supplier');

        $id = $create->json('data.id');

        $this->patchJson("/api/v1/suppliers/{$id}", [
            'notes' => 'Note here',
        ])->assertOk()->assertJsonPath('data.notes', 'Note here');

        $this->patchJson("/api/v1/suppliers/{$id}/status")->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->deleteJson("/api/v1/suppliers/{$id}")->assertOk();

        $this->assertDatabaseMissing('suppliers', ['id' => $id]);
    }

    public function test_members_route_requires_members_manage_permission(): void
    {
        Passport::actingAs(\App\Models\User::factory()->create());

        $this->getJson('/api/v1/members')->assertForbidden();
    }

    public function test_seed_template_members_suppliers_inserts_rows(): void
    {
        $this->seed(\Database\Seeders\TemplateMembersSuppliersSeeder::class);

        $this->assertSame(5, Member::query()->count());
        $this->assertSame(4, Supplier::query()->count());
        $this->assertDatabaseHas('members', ['phone' => '081234560001', 'name' => 'Budi Santoso']);
        $this->assertDatabaseHas('suppliers', ['name' => 'PT Sumber Pangan']);
    }
}
