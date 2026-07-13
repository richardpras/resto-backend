<?php

namespace App\Modules\Imports\Services;

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
- Gunakan template Excel (XLSX) untuk dropdown dan petunjuk kolom.
- Header bilingual: "Label ID / Label EN (field)" — field dalam kurung adalah kunci internal.
- ingredient type: ingredient | atk | asset
- supplier / table status: active | inactive
- available (menu): 1 or 0

Upload ZIP or XLSX in Settings → Master Import, preview first, then commit.
TXT;

    public function __construct(
        private readonly MasterImportTemplateSupport $templateSupport,
    ) {}

    /** @return array<string, array{headers: list<string>, examples: list<array<string, string>>}> */
    public static function sheetDefinitions(): array
    {
        return app(MasterImportTemplateSupport::class)->legacySheetDefinitions('phase1');
    }

    public function buildBundleZip(): string
    {
        return $this->templateSupport->buildZip(self::BUNDLE_README, 'phase1', 'master_import_tpl_');
    }

    public function buildWorkbookXlsx(int $outletId): string
    {
        return $this->templateSupport->buildXlsx(
            'Master Import Phase 1 — Operasional / Operations',
            'Isi sheet sesuai urutan. Baris kuning = contoh, hapus sebelum commit.',
            'Fill sheets in order. Yellow row = example, delete before commit.',
            'phase1',
            $outletId,
        );
    }
}
