<?php

namespace App\Modules\Imports\Services;

use App\Modules\Imports\Support\CsvTableParser;
use ZipArchive;

class MasterImportTemplateService
{
  public const BUNDLE_README = <<<'TXT'
Resto Apps — Master Import Phase 1
==================================

Import order (do not change file names when using the ZIP bundle):
  1. 01_ingredients.csv
  2. 02_opening_stock.csv
  3. 03_menu_categories.csv
  4. 04_menu_items.csv
  5. 05_recipes.csv
  6. 06_suppliers.csv
  7. 07_tables.csv

Tips:
- Keep the header row. Required columns are marked with * in the sample rows comment below.
- Use stable codes (import_code / code) so re-import updates instead of duplicating.
- ingredient type: ingredient | atk | asset
- supplier / table status: active | inactive
- available (menu): 1 or 0

Upload the ZIP in Settings → Master Import, preview first, then commit.
TXT;

    /** @return array<string, array{headers: list<string>, examples: list<array<string, string>>}> */
    public static function sheetDefinitions(): array
    {
        return [
            '01_ingredients.csv' => [
                'headers' => ['code', 'name', 'type', 'unit', 'min_qty', 'unit_price', 'notes'],
                'examples' => [
                    [
                        'code' => 'ING_FLOUR',
                        'name' => 'Tepung Terigu',
                        'type' => 'ingredient',
                        'unit' => 'kg',
                        'min_qty' => '5',
                        'unit_price' => '12000',
                        'notes' => '',
                    ],
                ],
            ],
            '02_opening_stock.csv' => [
                'headers' => ['ingredient_code', 'qty'],
                'examples' => [
                    ['ingredient_code' => 'ING_FLOUR', 'qty' => '25'],
                ],
            ],
            '03_menu_categories.csv' => [
                'headers' => ['code', 'name', 'sort_order', 'description'],
                'examples' => [
                    [
                        'code' => 'makanan',
                        'name' => 'Makanan',
                        'sort_order' => '10',
                        'description' => '',
                    ],
                ],
            ],
            '04_menu_items.csv' => [
                'headers' => ['code', 'category_code', 'name', 'price', 'emoji', 'available'],
                'examples' => [
                    [
                        'code' => 'MENU_NASI_GORENG',
                        'category_code' => 'makanan',
                        'name' => 'Nasi Goreng',
                        'price' => '35000',
                        'emoji' => '🍚',
                        'available' => '1',
                    ],
                ],
            ],
            '05_recipes.csv' => [
                'headers' => ['menu_code', 'ingredient_code', 'qty'],
                'examples' => [
                    ['menu_code' => 'MENU_NASI_GORENG', 'ingredient_code' => 'ING_FLOUR', 'qty' => '0.2'],
                ],
            ],
            '06_suppliers.csv' => [
                'headers' => ['code', 'name', 'contact', 'email', 'address', 'status'],
                'examples' => [
                    [
                        'code' => 'SUP_ABC',
                        'name' => 'Supplier ABC',
                        'contact' => '08123456789',
                        'email' => 'abc@example.com',
                        'address' => 'Jakarta',
                        'status' => 'active',
                    ],
                ],
            ],
            '07_tables.csv' => [
                'headers' => ['code', 'name', 'capacity', 'zone', 'status', 'active'],
                'examples' => [
                    [
                        'code' => 'T01',
                        'name' => 'Meja 1',
                        'capacity' => '4',
                        'zone' => 'Indoor',
                        'status' => 'active',
                        'active' => '1',
                    ],
                ],
            ],
        ];
    }

    public function buildBundleZip(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'master_import_tpl_');
        if ($tmp === false) {
            abort(500, 'Unable to create template archive.');
        }

        $zipPath = $tmp.'.zip';
        @unlink($tmp);

        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            abort(500, 'Unable to open template archive.');
        }

        $zip->addFromString('README.txt', self::BUNDLE_README);

        foreach (self::sheetDefinitions() as $filename => $definition) {
            $csv = CsvTableParser::toCsv($definition['headers'], $definition['examples']);
            $zip->addFromString($filename, $csv);
        }

        $zip->close();

        return $zipPath;
    }
}
