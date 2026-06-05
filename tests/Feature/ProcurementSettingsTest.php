<?php

namespace Tests\Feature;

use App\Models\Modules\Purchase\Domain\InventoryProcurementSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\ProcurementTestFixture;
use Tests\TestCase;

class ProcurementSettingsTest extends TestCase
{
    use RefreshDatabase;
    use ProcurementTestFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_procurement_setting_unique_per_inventory_item(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $this->postJson('/api/v1/procurement-settings', [
            'inventoryItemId' => $master['ingredientId'],
            'preferredSupplierId' => $master['supplierId'],
            'minimumOrderQty' => 10,
            'reorderQty' => 20,
            'leadTimeDays' => 5,
            'lastPurchasePrice' => 10000,
        ])->assertCreated();

        $duplicate = $this->postJson('/api/v1/procurement-settings', [
            'inventoryItemId' => $master['ingredientId'],
            'preferredSupplierId' => $master['supplierId'],
        ]);
        $duplicate->assertStatus(422);
    }

    public function test_preferred_supplier_lookup_works(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $this->postJson('/api/v1/procurement-settings', [
            'inventoryItemId' => $master['ingredientId'],
            'preferredSupplierId' => $master['supplierId'],
            'minimumOrderQty' => 5,
        ])->assertCreated();

        $service = app(\App\Modules\Purchase\Services\ProcurementMasterService::class);
        $supplier = $service->findPreferredSupplier((int) $master['ingredientId']);

        $this->assertNotNull($supplier);
        $this->assertSame((int) $master['supplierId'], (int) $supplier->id);

        $listed = $this->getJson('/api/v1/procurement-settings?inventoryItemId='.$master['ingredientId']);
        $listed->assertOk();
        $listed->assertJsonPath('data.0.preferredSupplierId', (string) $master['supplierId']);
        $listed->assertJsonPath('data.0.minimumOrderQty', 5);
    }

    public function test_procurement_setting_crud(): void
    {
        $outlet = $this->createOutlet();
        $this->actingAsProcurementUser($outlet);
        $master = $this->seedProcurementMasterData((int) $outlet->id);

        $created = $this->postJson('/api/v1/procurement-settings', [
            'inventoryItemId' => $master['ingredientId'],
            'reorderQty' => 15,
        ])->assertCreated();

        $id = $created->json('data.id');

        $this->patchJson("/api/v1/procurement-settings/{$id}", [
            'reorderQty' => 25,
            'lastPurchasePrice' => 12000,
        ])->assertOk()->assertJsonPath('data.reorderQty', 25);

        $this->deleteJson("/api/v1/procurement-settings/{$id}")->assertOk();
        $this->assertDatabaseMissing('inventory_procurement_settings', ['id' => $id]);
    }
}
