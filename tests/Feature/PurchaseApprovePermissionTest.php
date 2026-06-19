<?php

namespace Tests\Feature;

use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class PurchaseApprovePermissionTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    /** @return array{outletId:int,ingredientId:int,prId:int} */
    private function seedSubmittedPr(): array
    {
        $outlet = $this->createOutlet();
        $ingredientId = DB::table('ingredients')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Sugar',
            'type' => 'ingredient',
            'unit' => 'kg',
            'stock' => 1,
            'min' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsProcurementUser($outlet, manageOnly: true);

        $prId = (int) $this->postJson('/api/v1/purchase-requests', [
            'outletId' => $outlet->id,
            'requestedBy' => 'Staff',
            'items' => [
                ['inventoryItemId' => $ingredientId, 'quantity' => 3, 'unit' => 'kg'],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/purchase-requests/{$prId}/submit")->assertOk();

        return [
            'outletId' => (int) $outlet->id,
            'ingredientId' => $ingredientId,
            'prId' => $prId,
        ];
    }

    public function test_user_with_manage_only_can_approve_purchase_request(): void
    {
        $ctx = $this->seedSubmittedPr();

        $this->postJson("/api/v1/purchase-requests/{$ctx['prId']}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }

    public function test_user_without_purchase_permissions_cannot_approve_purchase_request(): void
    {
        $outlet = $this->createOutlet();
        $this->seedProcurementPermissions();

        $role = Role::query()->create([
            'name' => '__test_no_purchase__',
            'description' => 'No procurement',
        ]);

        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        $user->outlets()->sync([(int) $outlet->id]);

        $ingredientId = DB::table('ingredients')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Pepper',
            'type' => 'ingredient',
            'unit' => 'kg',
            'stock' => 1,
            'min' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsProcurementUser($outlet);
        $prId = (int) $this->postJson('/api/v1/purchase-requests', [
            'outletId' => $outlet->id,
            'requestedBy' => 'Staff',
            'items' => [
                ['inventoryItemId' => $ingredientId, 'quantity' => 1, 'unit' => 'kg'],
            ],
        ])->assertCreated()->json('data.id');
        $this->postJson("/api/v1/purchase-requests/{$prId}/submit")->assertOk();

        Passport::actingAs($user);
        $this->postJson("/api/v1/purchase-requests/{$prId}/approve")
            ->assertForbidden();
    }

    public function test_user_with_purchase_approve_can_approve_purchase_request(): void
    {
        $outlet = $this->createOutlet();
        $ingredientId = DB::table('ingredients')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outlet->id,
            'name' => 'Salt',
            'type' => 'ingredient',
            'unit' => 'kg',
            'stock' => 1,
            'min' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAsProcurementUser($outlet);

        $prId = (int) $this->postJson('/api/v1/purchase-requests', [
            'outletId' => $outlet->id,
            'requestedBy' => 'Staff',
            'items' => [
                ['inventoryItemId' => $ingredientId, 'quantity' => 2, 'unit' => 'kg'],
            ],
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/purchase-requests/{$prId}/submit")->assertOk();
        $this->postJson("/api/v1/purchase-requests/{$prId}/approve")
            ->assertOk()
            ->assertJsonPath('data.status', 'approved');
    }
}
