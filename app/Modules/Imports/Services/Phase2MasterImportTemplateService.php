<?php

namespace App\Modules\Imports\Services;

class Phase2MasterImportTemplateService
{
    public const BUNDLE_README = <<<'TXT'
Resto Apps — Master Import Phase 2
==================================

Import order (keep file names when using the ZIP bundle):
  1. 08_chart_of_accounts.csv
  2. 09_opening_balances.csv
  3. 10_customers.csv
  4. 11_members.csv
  5. 12_outlet_payment_methods.csv

Tips:
- Gunakan template Excel (XLSX) untuk dropdown dan petunjuk kolom.
- COA account codes are globally unique.
- Opening balance lines must balance (total debit = total credit).
- Payment method codes: cash, manual_qris, gateway_qris, gateway_ewallet, manual_transfer

Upload in Settings → Master Import (Phase 2), preview first, then commit.
TXT;

    public function __construct(
        private readonly MasterImportTemplateSupport $templateSupport,
    ) {}

    /** @return array<string, array{headers: list<string>, examples: list<array<string, string>>}> */
    public static function sheetDefinitions(): array
    {
        return app(MasterImportTemplateSupport::class)->legacySheetDefinitions('phase2');
    }

    public function buildBundleZip(): string
    {
        return $this->templateSupport->buildZip(self::BUNDLE_README, 'phase2', 'master_import_phase2_tpl_');
    }

    public function buildWorkbookXlsx(int $outletId): string
    {
        return $this->templateSupport->buildXlsx(
            'Master Import Phase 2 — CRM & Keuangan / CRM & Finance',
            'Isi sheet sesuai urutan. Saldo awal harus seimbang (debit = kredit).',
            'Fill sheets in order. Opening balances must balance (debit = credit).',
            'phase2',
            $outletId,
        );
    }
}
