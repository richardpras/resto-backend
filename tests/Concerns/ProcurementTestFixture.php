<?php

namespace Tests\Concerns;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

trait ProcurementTestFixture
{
    protected function seedProcurementPermissions(): void
    {
        $this->seed(UserManagementPermissionsSeeder::class);
    }

    protected function actingAsProcurementUser(?Outlet $outlet = null, bool $manageOnly = false): User
    {
        $this->seedProcurementPermissions();
        Artisan::call('passport:keys', ['--force' => true]);

        $roleName = $manageOnly ? '__test_procurement_manage_only__' : '__test_procurement_user__';
        $role = Role::query()->firstOrCreate(
            ['name' => $roleName],
            ['description' => 'Test fixture: procurement access'],
        );

        $codes = $manageOnly
            ? ['purchase.manage', 'suppliers.manage', 'outlets.view_all']
            : ['purchase.manage', 'purchase.approve', 'suppliers.manage', 'outlets.view_all'];

        $permissionIds = Permission::query()
            ->whereIn('code', $codes)
            ->pluck('id')
            ->all();
        $role->permissions()->sync($permissionIds);

        $user = User::factory()->create([
            'email' => 'procurement-fixture-'.uniqid('', true).'@test.local',
            'password' => 'secret123',
        ]);
        $user->roles()->sync([$role->id]);

        if ($outlet !== null) {
            $user->outlets()->sync([(int) $outlet->id]);
        }

        Passport::actingAs($user);

        return $user;
    }

    protected function createOutlet(string $name = 'Procurement Test Outlet'): Outlet
    {
        return Outlet::query()->create([
            'name' => $name,
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'proc-test-'.uniqid(),
        ]);
    }

    /** @return array{supplierId:int,ingredientId:int,prId:int,poId:int,poItemId:int} */
    protected function seedProcurementMasterData(int $outletId, string $suffix = '1'): array
    {
        $supplierId = DB::table('suppliers')->insertGetId([
            'name' => 'PT Vendor '.$suffix,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $ingredientId = DB::table('ingredients')->insertGetId([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'name' => 'Rice '.$suffix,
            'type' => 'ingredient',
            'unit' => 'kg',
            'stock' => 10,
            'min' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $prId = DB::table('purchase_requests_v2')->insertGetId([
            'request_no' => 'PR-'.$suffix,
            'outlet_id' => $outletId,
            'requested_by' => 'Test User',
            'status' => 'converted',
            'approved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $poId = DB::table('purchase_orders')->insertGetId([
            'outlet_id' => $outletId,
            'purchase_request_id' => $prId,
            'supplier_id' => $supplierId,
            'number' => 'PO-'.$suffix,
            'status' => 'approved',
            'order_date' => now()->toDateString(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $poItemId = DB::table('purchase_order_items')->insertGetId([
            'purchase_order_id' => $poId,
            'ingredient_id' => $ingredientId,
            'ordered_qty' => 100,
            'received_qty' => 0,
            'unit_price' => 10000,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return compact('supplierId', 'ingredientId', 'prId', 'poId', 'poItemId');
    }

    protected function seedAccountingAccounts(): void
    {
        DB::table('accounts')->insert([
            ['code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'cash_bank', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '1110', 'name' => 'Bank', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'bank', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '1111', 'name' => 'Bank BCA', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'bank', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '1300', 'name' => 'Inventory', 'type' => 'asset', 'subtype' => 'current_asset', 'category' => 'inventory', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '2100', 'name' => 'Accounts Payable', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'accounts_payable', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
            ['code' => '2140', 'name' => 'GRNI', 'type' => 'liability', 'subtype' => 'short_term_liability', 'category' => 'grni', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }

    protected function seedWarehouse(int $outletId, string $code = 'WH-01'): int
    {
        return (int) DB::table('warehouses')->insertGetId([
            'outlet_id' => $outletId,
            'code' => $code.'-'.uniqid(),
            'name' => 'Warehouse '.$code,
            'type' => 'outlet',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @param array<int,array{inventoryItemId:int,receivedQty:float,unitCost?:float}> $items */
    protected function createPostedGoodsReceipt(int $poId, int $outletId, array $items, ?int $warehouseId = null): int
    {
        $warehouseId ??= $this->seedWarehouse($outletId);

        $create = $this->postJson('/api/v1/goods-receipts', [
            'purchaseOrderId' => $poId,
            'warehouseId' => $warehouseId,
            'date' => now()->toDateString(),
            'items' => $items,
        ])->assertCreated();

        $grnId = (int) $create->json('data.id');
        $this->patchJson("/api/v1/goods-receipts/{$grnId}/receive")->assertOk();
        $this->patchJson("/api/v1/goods-receipts/{$grnId}/post")->assertOk();

        return $grnId;
    }

    /** @param array<int,array{inventoryItemId:int,qty?:float,invoicedQty?:float}> $items */
    protected function createApprovedInvoice(int $poId, int $grnId, array $items = [], float $tax = 0): int
    {
        $payload = [
            'purchaseOrderId' => $poId,
            'goodsReceiptId' => $grnId,
            'date' => now()->toDateString(),
            'tax' => $tax,
        ];
        if ($items !== []) {
            $payload['items'] = array_map(static fn (array $item): array => [
                'inventoryItemId' => $item['inventoryItemId'],
                'qty' => $item['invoicedQty'] ?? $item['qty'] ?? 0,
            ], $items);
        }

        $invoiceId = (int) $this->postJson('/api/v1/purchase-invoices', $payload)->assertCreated()->json('data.id');
        $this->patchJson("/api/v1/purchase-invoices/{$invoiceId}/submit")->assertOk();
        $this->patchJson("/api/v1/purchase-invoices/{$invoiceId}/approve")->assertOk();

        return $invoiceId;
    }

    /** @param array<int,array{invoiceId:int,allocatedAmount:float}> $allocations */
    protected function createPostedSupplierPayment(int $supplierId, int $outletId, float $amount, array $allocations, string $method = 'cash', ?string $bankAccountId = null): int
    {
        $payload = [
            'supplierId' => $supplierId,
            'outletId' => $outletId,
            'paymentDate' => now()->toDateString(),
            'paymentMethod' => $method,
            'amount' => $amount,
            'allocations' => array_map(static fn (array $row): array => [
                'invoiceId' => $row['invoiceId'],
                'allocatedAmount' => $row['allocatedAmount'],
            ], $allocations),
        ];
        if ($bankAccountId !== null) {
            $payload['bankAccountId'] = $bankAccountId;
        }

        $paymentId = (int) $this->postJson('/api/v1/supplier-payments', $payload)->assertCreated()->json('data.id');

        $this->patchJson("/api/v1/supplier-payments/{$paymentId}/approve")->assertOk();
        $this->patchJson("/api/v1/supplier-payments/{$paymentId}/post")->assertOk();

        return $paymentId;
    }
}
