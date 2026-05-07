<?php

namespace Tests\Feature;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class TableMasterApiTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_master_table_crud_respects_outlet_scope_and_unique_name(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();

        $outlet = Outlet::query()->create([
            'name' => 'Test Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 't-out-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        $list = $this->getJson('/api/v1/tables?outletId='.$outlet->id)->assertOk();
        $list->assertJsonPath('data', []);

        $this->postJson('/api/v1/tables', [
            'outletId' => $outlet->id,
            'name' => 'VIP-1',
            'capacity' => 6,
            'status' => 'active',
        ])->assertCreated()->assertJsonPath('data.name', 'VIP-1');

        $this->postJson('/api/v1/tables', [
            'outletId' => $outlet->id,
            'name' => 'VIP-1',
            'status' => 'active',
        ])->assertUnprocessable();

        $tid = RestaurantTable::query()->where('outlet_id', $outlet->id)->value('id');
        self::assertIsInt((int) $tid);

        $this->patchJson('/api/v1/tables/'.$tid, [
            'capacity' => 8,
            'status' => 'inactive',
        ])->assertOk()->assertJsonPath('data.capacity', 8)->assertJsonPath('data.status', 'inactive');

        $this->deleteJson('/api/v1/tables/'.$tid)->assertOk();
        $this->assertDatabaseMissing('tables', ['id' => $tid]);
    }

    public function test_create_order_sets_table_snapshot_when_table_id_matches_outlet(): void
    {
        $outlet = Outlet::query()->create([
            'name' => 'O2',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 't-out2-'.uniqid(),
        ]);

        $t = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'T5',
            'capacity' => 4,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/v1/orders', [
            'tenantId' => 1,
            'outletId' => $outlet->id,
            'code' => 'POS-TBL-1',
            'source' => 'pos',
            'orderType' => 'Dine-in',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => '201', 'name' => 'Item', 'qty' => 1, 'price' => 10000],
            ],
            'subtotal' => 10000,
            'tax' => 1000,
            'total' => 11000,
            'payments' => [],
            'tableId' => $t->id,
            'confirmedAt' => now()->toISOString(),
        ]);

        $response->assertCreated();
        $response->assertJsonPath('data.tableId', (int) $t->id);
        $response->assertJsonPath('data.tableName', 'T5');
        $response->assertJsonPath('data.tableNumber', 'T5');

        $this->assertDatabaseHas('orders', [
            'code' => 'POS-TBL-1',
            'table_id' => $t->id,
            'table_name' => 'T5',
        ]);
    }

    public function test_table_master_list_and_mutation_are_outlet_scoped_for_user(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $allowedOutlet = Outlet::query()->create([
            'name' => 'Allowed',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'allow-'.uniqid(),
        ]);
        $forbiddenOutlet = Outlet::query()->create([
            'name' => 'Forbidden',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'forbid-'.uniqid(),
        ]);

        $this->assignUserToOutlets($user, [$allowedOutlet->id]);

        $allowedTable = RestaurantTable::query()->create([
            'outlet_id' => $allowedOutlet->id,
            'name' => 'A1',
            'capacity' => 2,
            'status' => 'active',
        ]);
        $forbiddenTable = RestaurantTable::query()->create([
            'outlet_id' => $forbiddenOutlet->id,
            'name' => 'B1',
            'capacity' => 4,
            'status' => 'active',
        ]);

        $this->getJson('/api/v1/tables?outletId='.$allowedOutlet->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', (int) $allowedTable->id);

        $this->getJson('/api/v1/tables?outletId='.$forbiddenOutlet->id)->assertUnprocessable();

        $this->postJson('/api/v1/tables', [
            'outletId' => $forbiddenOutlet->id,
            'name' => 'B2',
            'status' => 'active',
        ])->assertUnprocessable();

        $this->patchJson('/api/v1/tables/'.$forbiddenTable->id, [
            'capacity' => 6,
        ])->assertNotFound();

        $this->deleteJson('/api/v1/tables/'.$forbiddenTable->id)->assertNotFound();
    }
}
