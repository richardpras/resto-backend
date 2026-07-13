<?php

namespace App\Modules\Imports\Services;

class Phase3MasterImportTemplateService
{
    public const BUNDLE_README = <<<'TXT'
Resto Apps — Master Import Phase 3
==================================

Import order (keep file names when using the ZIP bundle):
  1. 13_departments.csv
  2. 14_positions.csv
  3. 15_employees.csv
  4. 16_opening_loyalty_points.csv

Prerequisites:
- Phase 2 customers (10_customers.csv) if using opening loyalty points.

Tips:
- Gunakan template Excel (XLSX) untuk dropdown dan petunjuk kolom.
- employee_no must be unique per outlet.
- department_code / position_code link org structure.
- customer_code in sheet 16 must match import_code from customers.

Upload in Settings → Master Import (Phase 3), preview first, then commit.
TXT;

    public function __construct(
        private readonly MasterImportTemplateSupport $templateSupport,
    ) {}

    /** @return array<string, array{headers: list<string>, examples: list<array<string, string>>}> */
    public static function sheetDefinitions(): array
    {
        return app(MasterImportTemplateSupport::class)->legacySheetDefinitions('phase3');
    }

    public function buildBundleZip(): string
    {
        return $this->templateSupport->buildZip(self::BUNDLE_README, 'phase3', 'master_import_phase3_tpl_');
    }

    public function buildWorkbookXlsx(int $outletId): string
    {
        return $this->templateSupport->buildXlsx(
            'Master Import Phase 3 — HR & Loyalty',
            'Isi sheet sesuai urutan. Poin loyalty butuh pelanggan dari Fase 2.',
            'Fill sheets in order. Loyalty points require Phase 2 customers.',
            'phase3',
            $outletId,
        );
    }
}
