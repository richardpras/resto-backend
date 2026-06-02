<?php

namespace Tests\Feature;

use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
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
        $this->seedUserManagementGatePermissions();

        $permIds = Permission::query()
            ->whereIn('code', ['tables.view', 'tables.manage'])
            ->pluck('id')
            ->all();
        self::assertCount(2, $permIds);

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_table_outlet_scope__'.uniqid('', true)],
            ['description' => 'Tables CRUD without outlets.view_all (outlet assignment applies)'],
        );
        $role->permissions()->sync($permIds);

        $user = User::factory()->create([
            'email' => 'table-scope-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);

        Passport::actingAs($user);

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

    public function test_list_tables_allows_tables_manage_without_explicit_tables_view(): void
    {
        $this->seedUserManagementGatePermissions();

        $manageId = Permission::query()->where('code', 'tables.manage')->value('id');
        self::assertNotNull($manageId);

        $role = Role::query()->firstOrCreate(
            ['name' => '__test_tables_manage_only__'.uniqid('', true)],
            ['description' => 'Fixture: manage floor without separate tables.view'],
        );
        $role->permissions()->sync([(int) $manageId]);

        $user = User::factory()->create([
            'email' => 'tables-manage-only-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);

        $outlet = Outlet::query()->create([
            'name' => 'Outlet For Manage-Only',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'tbl-mgr-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        Passport::actingAs($user);

        $this->getJson('/api/v1/tables?outletId='.$outlet->id)->assertOk()->assertJsonPath('data', []);
    }

    public function test_table_list_returns_operational_projection_from_open_bill_and_qr_pending(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();

        $outlet = Outlet::query()->create([
            'name' => 'Projection Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'proj-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);

        $available = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'A1',
            'capacity' => 4,
            'status' => 'active',
        ]);
        $occupiedByQr = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'A2',
            'capacity' => 4,
            'status' => 'active',
        ]);
        $disabled = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'A3',
            'capacity' => 4,
            'status' => 'inactive',
        ]);

        QrOrderRequest::query()->create([
            'outlet_id' => $outlet->id,
            'table_id' => $occupiedByQr->id,
            'request_code' => 'QRO-PROJ-'.uniqid(),
            'customer_name' => 'Guest',
            'status' => 'pending_cashier_confirmation',
            'expires_at' => now()->addMinutes(10),
        ]);

        $response = $this->getJson('/api/v1/tables?outletId='.$outlet->id)->assertOk();

        $rows = collect($response->json('data'));
        $byId = $rows->keyBy('id');
        $this->assertSame('available', $byId->get((int) $available->id)['tableOperationalStatus']);
        $this->assertSame('occupied', $byId->get((int) $occupiedByQr->id)['tableOperationalStatus']);
        $this->assertSame('disabled', $byId->get((int) $disabled->id)['tableOperationalStatus']);
    }

    public function test_table_qr_management_and_resolvers_work_without_breaking_legacy_query(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'QR Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'qr-out-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [$outlet->id]);
        $table = RestaurantTable::query()->create([
            'outlet_id' => $outlet->id,
            'name' => 'A9',
            'capacity' => 4,
            'status' => 'active',
            'active' => true,
        ]);

        $generated = $this->postJson('/api/v1/tables/'.$table->id.'/qr/generate')->assertOk();
        $generated->assertJsonPath('data.qrEnabled', true);
        $publicId = (string) $generated->json('data.qrPublicId');
        $this->assertNotSame('', $publicId);

        $resolved = $this->getJson('/api/v1/qr/tables/'.$publicId)->assertOk();
        $resolved->assertJsonPath('data.outletId', (int) $outlet->id);
        $resolved->assertJsonPath('data.tableId', (int) $table->id);
        $resolved->assertJsonPath('data.qrPublicId', $publicId);

        $rotated = $this->postJson('/api/v1/tables/'.$table->id.'/qr/rotate')->assertOk();
        $newPublicId = (string) $rotated->json('data.qrPublicId');
        $this->assertNotSame($publicId, $newPublicId);
        $this->assertSame(2, (int) $rotated->json('data.qrVersion'));

        $this->postJson('/api/v1/tables/'.$table->id.'/qr/disable')->assertOk();
        $this->getJson('/api/v1/qr/tables/'.$newPublicId)->assertNotFound();
        $this->postJson('/api/v1/tables/'.$table->id.'/qr/enable')->assertOk();
        $this->getJson('/api/v1/qr/tables/'.$newPublicId)->assertOk();

        $legacy = $this->getJson('/api/v1/qr/legacy-resolve?outletId='.$outlet->id.'&tableId='.$table->id)->assertOk();
        $legacy->assertJsonPath('data.outletId', (int) $outlet->id);
        $legacy->assertJsonPath('data.tableId', (int) $table->id);
        $legacy->assertJsonPath('meta.compatibility', 'legacy-query');
    }
}
