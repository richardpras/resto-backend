<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\SystemSetting;
use App\Modules\Inventory\Support\InventoryCostingMethod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InventoryValuationFixture;
use Tests\TestCase;

class InventoryCostingMethodConfigTest extends TestCase
{
    use RefreshDatabase;
    use InventoryValuationFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
    }

    public function test_system_settings_exposes_inventory_costing_method(): void
    {
        $outlet = $this->createValuationOutlet();
        $this->actingAsInventoryUser($outlet);

        SystemSetting::query()->updateOrCreate(['id' => 1], [
            'enable_split_bill' => true,
            'enable_multi_payment' => true,
            'confirm_before_payment' => true,
            'enable_qr_ordering' => true,
            'inventory_costing_method' => InventoryCostingMethod::FIFO,
        ]);

        $response = $this->getJson('/api/v1/system-settings');

        $response->assertOk()
            ->assertJsonPath('data.inventoryCostingMethod', InventoryCostingMethod::FIFO);
    }

    public function test_patch_rejects_costing_method_change_without_force_flag_when_activity_exists(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $user = $this->actingAsInventoryUser($outlet);

        $service = app(\App\Modules\Inventory\Services\InventoryValuationService::class);
        $service->recordPurchase((int) $ingredient->id, (int) $outlet->id, 5, 10000);

        $payload = $this->baseSystemPayload();
        $payload['inventoryCostingMethod'] = InventoryCostingMethod::FIFO;

        $this->patchJson('/api/v1/system-settings', $payload)
            ->assertStatus(422);
    }

    public function test_patch_changes_costing_method_with_force_recalculate(): void
    {
        $outlet = $this->createValuationOutlet();
        $ingredient = $this->createIngredientForOutlet((int) $outlet->id);
        $this->actingAsInventoryUser($outlet);

        $service = app(\App\Modules\Inventory\Services\InventoryValuationService::class);
        $service->recordPurchase((int) $ingredient->id, (int) $outlet->id, 5, 10000);

        $payload = $this->baseSystemPayload();
        $payload['inventoryCostingMethod'] = InventoryCostingMethod::FIFO;
        $payload['forceRecalculateOnMethodChange'] = true;

        $this->patchJson('/api/v1/system-settings', $payload)
            ->assertOk()
            ->assertJsonPath('data.inventoryCostingMethod', InventoryCostingMethod::FIFO);

        $this->assertDatabaseHas('system_settings', [
            'inventory_costing_method' => InventoryCostingMethod::FIFO,
        ]);
    }

    /** @return array<string, mixed> */
    private function baseSystemPayload(): array
    {
        return [
            'enableSplitBill' => true,
            'enableMultiPayment' => true,
            'confirmBeforePayment' => true,
            'enableQROrdering' => true,
            'stockEnforcementMode' => 'deferred',
            'allowNegativeStock' => true,
            'inventoryCostingMethod' => InventoryCostingMethod::MOVING_AVERAGE,
        ];
    }
}
