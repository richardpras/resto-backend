<?php

namespace App\Modules\Imports\Services;

use App\Models\Modules\Imports\Domain\MasterImportBatch;
use App\Models\Modules\Inventory\Domain\Ingredient;
use App\Models\Modules\Inventory\Domain\InventoryStock;
use App\Models\Modules\Menu\Domain\MenuCategory;
use App\Models\Modules\Menu\Domain\MenuItem;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Supplier;
use App\Models\User;
use App\Modules\Imports\Support\CsvTableParser;
use App\Modules\Imports\Support\ImportSheetExtractor;
use App\Modules\Inventory\DTOs\CreateIngredientData;
use App\Modules\Inventory\Services\IngredientOutletStockLedger;
use App\Modules\Inventory\Services\InventoryCostingPolicyService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Menu\DTOs\CreateMenuItemData;
use App\Modules\Menu\Services\MenuService;
use App\Modules\Menu\Services\RecipeVersionService;
use App\Modules\Orders\Services\TableMasterService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Phase1MasterImportService
{
    /** @var list<string> */
    public const IMPORT_ORDER = [
        'ingredients',
        'opening_stock',
        'menu_categories',
        'menu_items',
        'recipes',
        'suppliers',
        'tables',
    ];

    /** @var array<string, string> */
    private const FILE_MAP = [
        'ingredients' => '01_ingredients.csv',
        'opening_stock' => '02_opening_stock.csv',
        'menu_categories' => '03_menu_categories.csv',
        'menu_items' => '04_menu_items.csv',
        'recipes' => '05_recipes.csv',
        'suppliers' => '06_suppliers.csv',
        'tables' => '07_tables.csv',
    ];

    public function __construct(
        private readonly InventoryService $inventoryService,
        private readonly IngredientOutletStockLedger $ingredientOutletStockLedger,
        private readonly InventoryValuationService $inventoryValuationService,
        private readonly InventoryCostingPolicyService $inventoryCostingPolicyService,
        private readonly MenuService $menuService,
        private readonly RecipeVersionService $recipeVersionService,
        private readonly TableMasterService $tableMasterService,
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function importBundle(User $user, array $payload): array
    {
        $outletId = (int) ($payload['outletId'] ?? 0);
        $tenantId = isset($payload['tenantId']) ? (int) $payload['tenantId'] : null;
        $preview = (bool) ($payload['preview'] ?? false);
        $file = $payload['file'] ?? null;

        abort_if($outletId < 1, 422, 'outletId is required.');
        abort_if(! $file instanceof UploadedFile, 422, 'ZIP file is required.');
        $this->assertOutletAllowed($user, $outletId);

        $sheets = ImportSheetExtractor::extract($file);
        $context = $this->buildContext($outletId, $tenantId);
        $sections = [];

        foreach (self::IMPORT_ORDER as $type) {
            $filename = self::FILE_MAP[$type];
            $content = $sheets[$filename] ?? '';
            $sections[$type] = $this->processSection($type, $content, $context, $user, $preview);
        }

        return $this->finalizeResult('phase1_bundle', $sections, $preview, $user, $outletId, $tenantId, $file->getClientOriginalName());
    }

    /**
     * @return array<string, mixed>
     */
    public function importType(User $user, string $type, array $payload): array
    {
        abort_unless(in_array($type, self::IMPORT_ORDER, true), 404, 'Unknown import type.');

        $outletId = (int) ($payload['outletId'] ?? 0);
        $tenantId = isset($payload['tenantId']) ? (int) $payload['tenantId'] : null;
        $preview = (bool) ($payload['preview'] ?? false);
        $csv = (string) ($payload['csv'] ?? '');

        abort_if($outletId < 1, 422, 'outletId is required.');
        abort_if(trim($csv) === '', 422, 'CSV content is required.');
        $this->assertOutletAllowed($user, $outletId);

        $context = $this->buildContext($outletId, $tenantId);
        $section = $this->processSection($type, $csv, $context, $user, $preview);

        return $this->finalizeResult(
            'phase1_'.$type,
            [$type => $section],
            $preview,
            $user,
            $outletId,
            $tenantId,
            (string) ($payload['filename'] ?? self::FILE_MAP[$type]),
        );
    }

    /**
     * @return array{outletId:int,tenantId:?int,ingredientByCode:array<string,Ingredient>,categoryByCode:array<string,MenuCategory>,menuByCode:array<string,MenuItem>}
     */
    private function buildContext(int $outletId, ?int $tenantId): array
    {
        $ingredientQuery = Ingredient::query()->where('outlet_id', $outletId);
        if ($tenantId !== null && $tenantId > 0) {
            $ingredientQuery->where('tenant_id', $tenantId);
        }
        $ingredients = $ingredientQuery->get()->keyBy(fn (Ingredient $row) => strtolower((string) $row->import_code));

        $categoryQuery = MenuCategory::query();
        if ($tenantId !== null && $tenantId > 0) {
            $categoryQuery->where('tenant_id', $tenantId);
        }
        $categories = $categoryQuery->get()->keyBy(fn (MenuCategory $row) => strtolower((string) $row->code));

        $menuQuery = MenuItem::query()->where('outlet_id', $outletId);
        if ($tenantId !== null && $tenantId > 0) {
            $menuQuery->where('tenant_id', $tenantId);
        }
        $menus = $menuQuery->get()->keyBy(fn (MenuItem $row) => strtolower((string) $row->import_code));

        return [
            'outletId' => $outletId,
            'tenantId' => $tenantId,
            'ingredientByCode' => $ingredients->all(),
            'categoryByCode' => $categories->all(),
            'menuByCode' => $menus->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}
     */
    private function processSection(string $type, string $csv, array &$context, User $user, bool $preview): array
    {
        $rows = CsvTableParser::parse($csv);
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'previewRows' => []];

        $execute = function () use ($type, $rows, &$context, $user, $preview, &$result): void {
            switch ($type) {
                case 'ingredients':
                    $this->importIngredients($rows, $context, $user, $preview, $result);
                    break;
                case 'opening_stock':
                    $this->importOpeningStock($rows, $context, $user, $preview, $result);
                    break;
                case 'menu_categories':
                    $this->importMenuCategories($rows, $context, $preview, $result);
                    break;
                case 'menu_items':
                    $this->importMenuItems($rows, $context, $preview, $result);
                    break;
                case 'recipes':
                    $this->importRecipes($rows, $context, $user, $preview, $result);
                    break;
                case 'suppliers':
                    $this->importSuppliers($rows, $preview, $result);
                    break;
                case 'tables':
                    $this->importTables($rows, $context, $user, $preview, $result);
                    break;
            }
        };

        if (! $preview) {
            DB::transaction($execute);
        } else {
            $execute();
        }

        return $result;
    }

    /**
     * @param  list<array{row:int,data:array<string,string>}>  $rows
     * @param  array<string, mixed>  $context
     * @param  array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}  $result
     */
    private function importIngredients(array $rows, array &$context, User $user, bool $preview, array &$result): void
    {
        foreach ($rows as $entry) {
            $row = $entry['row'];
            $data = $entry['data'];
            $code = strtolower(trim($data['code'] ?? ''));
            $name = trim($data['name'] ?? '');
            $type = strtolower(trim($data['type'] ?? 'ingredient'));
            $unit = trim($data['unit'] ?? '');

            if ($code === '' || $name === '' || $unit === '') {
                $result['errors'][] = ['row' => $row, 'message' => 'code, name, and unit are required.'];

                continue;
            }
            if (! in_array($type, ['ingredient', 'atk', 'asset'], true)) {
                $result['errors'][] = ['row' => $row, 'message' => 'type must be ingredient, atk, or asset.'];

                continue;
            }

            $min = $this->toFloat($data['min_qty'] ?? '0');
            $price = $this->toNullableFloat($data['unit_price'] ?? null);
            $notes = trim($data['notes'] ?? '') ?: null;

            $existing = $context['ingredientByCode'][$code] ?? null;
            if ($existing instanceof Ingredient) {
                $result['previewRows'][] = ['code' => $code, 'action' => 'update', 'name' => $name];
                if ($preview) {
                    $result['updated']++;

                    continue;
                }
                $existing->fill([
                    'name' => $name,
                    'type' => $type,
                    'unit' => $unit,
                    'min' => $min,
                    'price' => $price,
                    'notes' => $notes,
                ])->save();
                $result['updated']++;

                continue;
            }

            $result['previewRows'][] = ['code' => $code, 'action' => 'create', 'name' => $name];
            if ($preview) {
                $stub = new Ingredient([
                    'import_code' => trim($data['code']),
                    'name' => $name,
                    'type' => $type,
                    'unit' => $unit,
                    'price' => $price,
                ]);
                $stub->id = -$row;
                $context['ingredientByCode'][$code] = $stub;
                $result['created']++;

                continue;
            }

            $ingredient = $this->inventoryService->createIngredient(
                new CreateIngredientData(
                    tenantId: $context['tenantId'],
                    outletId: $context['outletId'],
                    name: $name,
                    type: $type,
                    unit: $unit,
                    stock: 0,
                    min: $min,
                    price: $price,
                    notes: $notes,
                ),
                $user,
            );
            $ingredient->import_code = trim($data['code']);
            $ingredient->save();
            $context['ingredientByCode'][$code] = $ingredient->fresh() ?? $ingredient;
            $result['created']++;
        }
    }

    /**
     * @param  list<array{row:int,data:array<string,string>}>  $rows
     * @param  array<string, mixed>  $context
     * @param  array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}  $result
     */
    private function importOpeningStock(array $rows, array &$context, User $user, bool $preview, array &$result): void
    {
        foreach ($rows as $entry) {
            $row = $entry['row'];
            $data = $entry['data'];
            $code = strtolower(trim($data['ingredient_code'] ?? ''));
            $qty = $this->toFloat($data['qty'] ?? '0');

            if ($code === '') {
                $result['errors'][] = ['row' => $row, 'message' => 'ingredient_code is required.'];

                continue;
            }
            if ($qty <= 0) {
                $result['skipped']++;

                continue;
            }

            $ingredient = $context['ingredientByCode'][$code] ?? null;
            if (! $ingredient instanceof Ingredient) {
                $result['errors'][] = ['row' => $row, 'message' => "Ingredient code [{$data['ingredient_code']}] not found."];

                continue;
            }

            $result['previewRows'][] = ['ingredientCode' => $data['ingredient_code'], 'qty' => $qty];
            if ($preview) {
                $result['created']++;

                continue;
            }

            $outletId = (int) $context['outletId'];
            $ledgerStock = InventoryStock::query()
                ->where('ingredient_id', $ingredient->id)
                ->where('outlet_id', $outletId)
                ->value('stock');
            if ($ledgerStock !== null && (float) $ledgerStock > 0) {
                $result['skipped']++;

                continue;
            }

            $unitCost = (float) ($ingredient->price ?? 0);
            $movement = $this->ingredientOutletStockLedger->apply(
                $outletId,
                (int) $ingredient->id,
                'purchase',
                $qty,
                'master_import_opening',
                (string) $ingredient->id,
                [
                    'cost_method' => $this->inventoryCostingPolicyService->getMethod(),
                    'unit_cost' => $unitCost,
                    'event' => 'master_import_opening_stock',
                ],
            );
            $this->inventoryValuationService->recordPurchase(
                (int) $ingredient->id,
                $outletId,
                $qty,
                $unitCost,
                null,
                $user,
                (int) $movement->id,
            );
            $ingredient->stock = $qty;
            $ingredient->save();
            $result['created']++;
        }
    }

    /**
     * @param  list<array{row:int,data:array<string,string>}>  $rows
     * @param  array<string, mixed>  $context
     * @param  array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}  $result
     */
    private function importMenuCategories(array $rows, array &$context, bool $preview, array &$result): void
    {
        foreach ($rows as $entry) {
            $row = $entry['row'];
            $data = $entry['data'];
            $code = strtolower(trim($data['code'] ?? ''));
            $name = trim($data['name'] ?? '');

            if ($code === '' || $name === '') {
                $result['errors'][] = ['row' => $row, 'message' => 'code and name are required.'];

                continue;
            }

            $sortOrder = (int) ($data['sort_order'] ?? 100);
            $description = trim($data['description'] ?? '') ?: null;
            $existing = $context['categoryByCode'][$code] ?? null;

            if ($existing instanceof MenuCategory) {
                $result['previewRows'][] = ['code' => $data['code'], 'action' => 'update', 'name' => $name];
                if ($preview) {
                    $result['updated']++;

                    continue;
                }
                $existing->fill([
                    'name' => $name,
                    'name_en' => $name,
                    'name_id' => $name,
                    'description' => $description,
                    'sort_order' => $sortOrder,
                ])->save();
                $result['updated']++;

                continue;
            }

            $result['previewRows'][] = ['code' => $data['code'], 'action' => 'create', 'name' => $name];
            if ($preview) {
                $stub = new MenuCategory([
                    'code' => $data['code'],
                    'name' => $name,
                ]);
                $stub->id = -$row;
                $context['categoryByCode'][$code] = $stub;
                $result['created']++;

                continue;
            }

            $category = MenuCategory::query()->create([
                'tenant_id' => $context['tenantId'],
                'code' => $data['code'],
                'name' => $name,
                'name_en' => $name,
                'name_id' => $name,
                'description' => $description,
                'sort_order' => $sortOrder,
                'is_active' => true,
            ]);
            $context['categoryByCode'][$code] = $category;
            $result['created']++;
        }
    }

    /**
     * @param  list<array{row:int,data:array<string,string>}>  $rows
     * @param  array<string, mixed>  $context
     * @param  array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}  $result
     */
    private function importMenuItems(array $rows, array &$context, bool $preview, array &$result): void
    {
        foreach ($rows as $entry) {
            $row = $entry['row'];
            $data = $entry['data'];
            $code = strtolower(trim($data['code'] ?? ''));
            $categoryCode = strtolower(trim($data['category_code'] ?? ''));
            $name = trim($data['name'] ?? '');
            $price = $this->toFloat($data['price'] ?? '0');

            if ($code === '' || $categoryCode === '' || $name === '') {
                $result['errors'][] = ['row' => $row, 'message' => 'code, category_code, and name are required.'];

                continue;
            }

            $category = $context['categoryByCode'][$categoryCode] ?? null;
            if (! $category instanceof MenuCategory) {
                $result['errors'][] = ['row' => $row, 'message' => "Category code [{$data['category_code']}] not found."];

                continue;
            }

            $available = $this->toBool($data['available'] ?? '1');
            $emoji = trim($data['emoji'] ?? '') ?: null;
            $existing = $context['menuByCode'][$code] ?? null;

            if ($existing instanceof MenuItem) {
                $result['previewRows'][] = ['code' => $data['code'], 'action' => 'update', 'name' => $name];
                if ($preview) {
                    $result['updated']++;

                    continue;
                }
                $existing->fill([
                    'name' => $name,
                    'category' => (string) $category->name,
                    'menu_category_id' => (int) $category->id,
                    'emoji' => $emoji,
                    'price' => $price,
                    'available' => $available,
                ])->save();
                $result['updated']++;

                continue;
            }

            $result['previewRows'][] = ['code' => $data['code'], 'action' => 'create', 'name' => $name];
            if ($preview) {
                $stub = new MenuItem([
                    'import_code' => trim($data['code']),
                    'name' => $name,
                ]);
                $stub->id = -$row;
                $context['menuByCode'][$code] = $stub;
                $result['created']++;

                continue;
            }

            $created = $this->menuService->create(new CreateMenuItemData(
                tenantId: $context['tenantId'],
                outletId: $context['outletId'],
                name: $name,
                menuCategoryId: (int) $category->id,
                category: (string) $category->name,
                emoji: $emoji,
                price: $price,
                available: $available,
            ));
            $menuItem = MenuItem::query()->find((int) $created->id);
            if ($menuItem !== null) {
                $menuItem->import_code = $data['code'];
                $menuItem->save();
                $context['menuByCode'][$code] = $menuItem;
            }
            $result['created']++;
        }
    }

    /**
     * @param  list<array{row:int,data:array<string,string>}>  $rows
     * @param  array<string, mixed>  $context
     * @param  array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}  $result
     */
    private function importRecipes(array $rows, array &$context, User $user, bool $preview, array &$result): void
    {
        $grouped = [];
        foreach ($rows as $entry) {
            $row = $entry['row'];
            $data = $entry['data'];
            $menuCode = strtolower(trim($data['menu_code'] ?? ''));
            $ingredientCode = strtolower(trim($data['ingredient_code'] ?? ''));
            $qty = $this->toFloat($data['qty'] ?? '0');

            if ($menuCode === '' || $ingredientCode === '') {
                $result['errors'][] = ['row' => $row, 'message' => 'menu_code and ingredient_code are required.'];

                continue;
            }
            if ($qty <= 0) {
                $result['errors'][] = ['row' => $row, 'message' => 'qty must be greater than zero.'];

                continue;
            }

            $menu = $context['menuByCode'][$menuCode] ?? null;
            if (! $menu instanceof MenuItem) {
                $result['errors'][] = ['row' => $row, 'message' => "Menu code [{$data['menu_code']}] not found."];

                continue;
            }
            $ingredient = $context['ingredientByCode'][$ingredientCode] ?? null;
            if (! $ingredient instanceof Ingredient) {
                $result['errors'][] = ['row' => $row, 'message' => "Ingredient code [{$data['ingredient_code']}] not found."];

                continue;
            }
            if ($ingredient->type !== 'ingredient') {
                $result['errors'][] = ['row' => $row, 'message' => 'Recipes can only use items with type ingredient.'];

                continue;
            }

            $grouped[(int) $menu->id]['menu'] = $menu;
            $grouped[(int) $menu->id]['lines'][] = [
                'inventoryItemId' => (int) $ingredient->id,
                'quantity' => $qty,
                'unit' => $ingredient->unit,
            ];
        }

        foreach ($grouped as $menuId => $bundle) {
            /** @var MenuItem $menu */
            $menu = $bundle['menu'];
            $recipes = $bundle['lines'];
            $result['previewRows'][] = [
                'menuCode' => $menu->import_code,
                'lineCount' => count($recipes),
            ];
            if ($preview) {
                $result['updated']++;

                continue;
            }
            $this->recipeVersionService->createVersionFromRecipes($menuId, $recipes, $user, 'master_import_phase1');
            $result['updated']++;
        }
    }

    /**
     * @param  list<array{row:int,data:array<string,string>}>  $rows
     * @param  array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}  $result
     */
    private function importSuppliers(array $rows, bool $preview, array &$result): void
    {
        foreach ($rows as $entry) {
            $row = $entry['row'];
            $data = $entry['data'];
            $code = strtolower(trim($data['code'] ?? ''));
            $name = trim($data['name'] ?? '');

            if ($code === '' || $name === '') {
                $result['errors'][] = ['row' => $row, 'message' => 'code and name are required.'];

                continue;
            }

            $status = strtolower(trim($data['status'] ?? 'active'));
            if (! in_array($status, ['active', 'inactive'], true)) {
                $result['errors'][] = ['row' => $row, 'message' => 'status must be active or inactive.'];

                continue;
            }

            $existing = Supplier::query()->where('import_code', $data['code'])->first();
            $attributes = [
                'name' => $name,
                'contact' => trim($data['contact'] ?? '') ?: null,
                'email' => trim($data['email'] ?? '') ?: null,
                'address' => trim($data['address'] ?? '') ?: null,
                'status' => $status,
                'is_active' => $status === 'active',
            ];

            if ($existing !== null) {
                $result['previewRows'][] = ['code' => $data['code'], 'action' => 'update'];
                if ($preview) {
                    $result['updated']++;

                    continue;
                }
                $existing->fill($attributes)->save();
                $result['updated']++;

                continue;
            }

            $result['previewRows'][] = ['code' => $data['code'], 'action' => 'create'];
            if ($preview) {
                $result['created']++;

                continue;
            }

            Supplier::query()->create(array_merge($attributes, ['import_code' => $data['code']]));
            $result['created']++;
        }
    }

    /**
     * @param  list<array{row:int,data:array<string,string>}>  $rows
     * @param  array<string, mixed>  $context
     * @param  array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}  $result
     */
    private function importTables(array $rows, array $context, User $user, bool $preview, array &$result): void
    {
        foreach ($rows as $entry) {
            $row = $entry['row'];
            $data = $entry['data'];
            $code = trim($data['code'] ?? '');
            $name = trim($data['name'] ?? '');

            if ($code === '' || $name === '') {
                $result['errors'][] = ['row' => $row, 'message' => 'code and name are required.'];

                continue;
            }

            $status = strtolower(trim($data['status'] ?? 'active'));
            if (! in_array($status, ['active', 'inactive'], true)) {
                $result['errors'][] = ['row' => $row, 'message' => 'status must be active or inactive.'];

                continue;
            }

            $existing = RestaurantTable::query()
                ->where('outlet_id', $context['outletId'])
                ->where('code', $code)
                ->first();

            $payload = [
                'outletId' => $context['outletId'],
                'code' => $code,
                'name' => $name,
                'capacity' => isset($data['capacity']) && $data['capacity'] !== '' ? (int) $data['capacity'] : null,
                'zone' => trim($data['zone'] ?? '') ?: null,
                'status' => $status,
                'active' => $this->toBool($data['active'] ?? '1'),
            ];

            if ($existing !== null) {
                $result['previewRows'][] = ['code' => $code, 'action' => 'update'];
                if ($preview) {
                    $result['updated']++;

                    continue;
                }
                $this->tableMasterService->update($user, (int) $existing->id, $payload);
                $result['updated']++;

                continue;
            }

            $result['previewRows'][] = ['code' => $code, 'action' => 'create'];
            if ($preview) {
                $result['created']++;

                continue;
            }

            $this->tableMasterService->create($user, $payload);
            $result['created']++;
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    private function finalizeResult(
        string $importType,
        array $sections,
        bool $preview,
        User $user,
        int $outletId,
        ?int $tenantId,
        string $filename,
    ): array {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errorCount = 0;

        foreach ($sections as $section) {
            $created += (int) ($section['created'] ?? 0);
            $updated += (int) ($section['updated'] ?? 0);
            $skipped += (int) ($section['skipped'] ?? 0);
            $errorCount += count($section['errors'] ?? []);
        }

        $canCommit = $errorCount === 0;
        $batch = null;

        if (! $preview) {
            $batch = MasterImportBatch::query()->create([
                'outlet_id' => $outletId,
                'tenant_id' => $tenantId,
                'import_type' => $importType,
                'filename' => $filename,
                'created_count' => $created,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
                'error_count' => $errorCount,
                'summary_json' => ['sections' => $sections],
                'created_by_user_id' => $user->id,
            ]);
        }

        return [
            'preview' => $preview,
            'canCommit' => $canCommit,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errorCount' => $errorCount,
            'sections' => $sections,
            'batchId' => $batch?->id,
        ];
    }

    private function assertOutletAllowed(User $user, int $outletId): void
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outletId is invalid.'],
            ]);
        }
    }

    private function toFloat(string $value): float
    {
        return (float) str_replace(',', '.', trim($value));
    }

    private function toNullableFloat(?string $value): ?float
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        return $this->toFloat($value);
    }

    private function toBool(string $value): bool
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, ['1', 'true', 'yes', 'y', 'active'], true);
    }
}
