<?php

namespace Tests\Feature;

use App\Models\Modules\Menu\Domain\MenuCategory;
use App\Models\Modules\Menu\Domain\MenuCategoryPrinterMapping;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\AccountingRemediationFixture;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class MenuCategoryPrinterMappingTest extends TestCase
{
    use RefreshDatabase;
    use AccountingRemediationFixture;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_category_printer_mapping_crud(): void
    {
        [$user, $outlet] = $this->actAsAdminWithOutlet('MC-MAP');
        $category = MenuCategory::query()->create([
            'tenant_id' => 1,
            'code' => 'food',
            'name' => 'Food',
            'is_active' => true,
        ]);
        $profile = PrinterProfile::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $outlet->id,
            'code' => 'kitchen-main',
            'name' => 'Kitchen',
            'station' => 'kitchen',
            'connection_type' => 'lan',
            'ip_address' => '127.0.0.1',
            'is_active' => true,
        ]);

        $save = $this->actingAs($user, 'api')->postJson('/api/v1/menu-category-printer-mappings', [
            'outletId' => (int) $outlet->id,
            'menuCategoryId' => (int) $category->id,
            'printerProfileId' => (int) $profile->id,
            'priority' => 10,
            'isActive' => true,
        ]);
        $save->assertCreated();
        $mappingId = (int) $save->json('data.id');

        $list = $this->actingAs($user, 'api')->getJson('/api/v1/menu-category-printer-mappings?outletId='.(int) $outlet->id);
        $list->assertOk();
        $this->assertSame($mappingId, (int) $list->json('data.0.id'));

        $delete = $this->actingAs($user, 'api')->deleteJson("/api/v1/menu-category-printer-mappings/{$mappingId}");
        $delete->assertOk();
        $this->assertSame(0, MenuCategoryPrinterMapping::query()->whereKey($mappingId)->count());
    }
}
