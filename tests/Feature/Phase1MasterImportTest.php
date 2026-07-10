<?php

namespace Tests\Feature;

use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Menu\Domain\MenuCategory;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Supplier;
use App\Modules\Imports\Services\MasterImportTemplateService;
use App\Modules\Imports\Services\Phase1MasterImportService;
use App\Modules\Imports\Support\CsvTableParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\BugReportTestFixture;
use Tests\TestCase;
use ZipArchive;

class Phase1MasterImportTest extends TestCase
{
    use BugReportTestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_phase1_template_download(): void
    {
        $outlet = $this->createOutlet();
        $user = $this->createUserWithPermission('settings.manage', $outlet);
        Passport::actingAs($user);

        $this->get('/api/v1/imports/phase1/template')
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_phase1_bundle_preview_and_commit(): void
    {
        $outlet = $this->createOutlet();
        $user = $this->createUserWithPermission('settings.manage', $outlet);

        $zipPath = $this->buildSampleZipOnDisk();
        $service = app(Phase1MasterImportService::class);

        $preview = $service->importBundle($user, [
            'outletId' => $outlet->id,
            'tenantId' => 1,
            'preview' => true,
            'file' => new UploadedFile($zipPath, 'phase1.zip', 'application/zip', null, true),
        ]);
        $this->assertTrue($preview['canCommit']);
        $this->assertSame(1, $preview['sections']['ingredients']['created']);

        $commit = $service->importBundle($user, [
            'outletId' => $outlet->id,
            'tenantId' => 1,
            'preview' => false,
            'file' => new UploadedFile($this->buildSampleZipOnDisk(), 'phase1.zip', 'application/zip', null, true),
        ]);
        $this->assertFalse($commit['preview']);
        $this->assertSame(0, $commit['errorCount']);
        $this->assertSame(1, $commit['sections']['menu_items']['created']);

        $this->assertDatabaseHas('ingredients', [
            'outlet_id' => $outlet->id,
            'import_code' => 'ING_FLOUR',
            'name' => 'Tepung',
        ]);
        $this->assertDatabaseHas('menu_categories', [
            'tenant_id' => 1,
            'code' => 'makanan',
        ]);
        $this->assertDatabaseHas('menu_items', [
            'outlet_id' => $outlet->id,
            'import_code' => 'MENU_NG',
        ]);
        $this->assertDatabaseHas('suppliers', [
            'import_code' => 'SUP_ABC',
        ]);
        $this->assertDatabaseHas('tables', [
            'outlet_id' => $outlet->id,
            'code' => 'T01',
        ]);

        $ingredient = Ingredient::query()->where('import_code', 'ING_FLOUR')->first();
        $this->assertNotNull($ingredient);
        $this->assertGreaterThan(0, (float) $ingredient->stock);

        $menu = MenuItem::query()->where('import_code', 'MENU_NG')->first();
        $this->assertNotNull($menu);
        $this->assertTrue($menu->recipeVersions()->exists());
    }

    public function test_phase1_single_type_csv_endpoint(): void
    {
        $outlet = $this->createOutlet();
        $user = $this->createUserWithPermission('inventory.manage', $outlet);
        Passport::actingAs($user);

        $csv = CsvTableParser::toCsv(
            ['code', 'name', 'type', 'unit', 'min_qty', 'unit_price', 'notes'],
            [[
                'code' => 'ING_SUGAR',
                'name' => 'Gula',
                'type' => 'ingredient',
                'unit' => 'kg',
                'min_qty' => '2',
                'unit_price' => '15000',
                'notes' => '',
            ]],
        );

        $this->postJson('/api/v1/imports/phase1/ingredients', [
            'outletId' => $outlet->id,
            'tenantId' => 1,
            'preview' => false,
            'csv' => $csv,
        ])->assertOk();

        $this->assertDatabaseHas('ingredients', [
            'import_code' => 'ING_SUGAR',
            'name' => 'Gula',
        ]);
    }

    private function createOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Import Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'imp-'.uniqid('', true),
        ]);
    }

    private function buildSampleZipOnDisk(): string
    {
        $definitions = MasterImportTemplateService::sheetDefinitions();
        $tmp = tempnam(sys_get_temp_dir(), 'phase1_import_test_');
        $zipPath = $tmp.'.zip';
        @unlink($tmp);

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);

        $zip->addFromString('01_ingredients.csv', CsvTableParser::toCsv(
            $definitions['01_ingredients.csv']['headers'],
            [[
                'code' => 'ING_FLOUR',
                'name' => 'Tepung',
                'type' => 'ingredient',
                'unit' => 'kg',
                'min_qty' => '1',
                'unit_price' => '10000',
                'notes' => '',
            ]],
        ));
        $zip->addFromString('02_opening_stock.csv', CsvTableParser::toCsv(
            $definitions['02_opening_stock.csv']['headers'],
            [['ingredient_code' => 'ING_FLOUR', 'qty' => '12']],
        ));
        $zip->addFromString('03_menu_categories.csv', CsvTableParser::toCsv(
            $definitions['03_menu_categories.csv']['headers'],
            [['code' => 'makanan', 'name' => 'Makanan', 'sort_order' => '10', 'description' => '']],
        ));
        $zip->addFromString('04_menu_items.csv', CsvTableParser::toCsv(
            $definitions['04_menu_items.csv']['headers'],
            [[
                'code' => 'MENU_NG',
                'category_code' => 'makanan',
                'name' => 'Nasi Goreng',
                'price' => '30000',
                'emoji' => '',
                'available' => '1',
            ]],
        ));
        $zip->addFromString('05_recipes.csv', CsvTableParser::toCsv(
            $definitions['05_recipes.csv']['headers'],
            [['menu_code' => 'MENU_NG', 'ingredient_code' => 'ING_FLOUR', 'qty' => '0.15']],
        ));
        $zip->addFromString('06_suppliers.csv', CsvTableParser::toCsv(
            $definitions['06_suppliers.csv']['headers'],
            [[
                'code' => 'SUP_ABC',
                'name' => 'Supplier ABC',
                'contact' => '',
                'email' => '',
                'address' => '',
                'status' => 'active',
            ]],
        ));
        $zip->addFromString('07_tables.csv', CsvTableParser::toCsv(
            $definitions['07_tables.csv']['headers'],
            [[
                'code' => 'T01',
                'name' => 'Meja 1',
                'capacity' => '4',
                'zone' => 'Indoor',
                'status' => 'active',
                'active' => '1',
            ]],
        ));
        $zip->close();

        return $zipPath;
    }
}
