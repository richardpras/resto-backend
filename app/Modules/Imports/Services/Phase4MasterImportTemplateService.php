<?php

namespace App\Modules\Imports\Services;

class Phase4MasterImportTemplateService
{
    public const BUNDLE_README = <<<'TXT'
Resto Apps — Master Import Phase 4
==================================

Import order (keep file / sheet names when using ZIP or XLSX):
  1. 17_employee_salary_profiles.csv (sheet: 17_employee_salary_profiles)

Prerequisites:
- Phase 3 employees (15_employees.csv) must exist; employee_no links each row.

Tips:
- Gunakan template Excel (XLSX) untuk dropdown dan petunjuk kolom.
- One salary profile per employee; existing profiles are updated.
- overtime_rate_type: fixed_hourly | multiplier_hourly_salary
- When attendance_deduction_enabled is 1, attendance_deduction_per_day is required.

Upload ZIP or XLSX in Settings → Master Import (Phase 4), preview first, then commit.
TXT;

    public function __construct(
        private readonly MasterImportTemplateSupport $templateSupport,
    ) {}

    /** @return array<string, array{headers: list<string>, examples: list<array<string, string>>}> */
    public static function sheetDefinitions(): array
    {
        return app(MasterImportTemplateSupport::class)->legacySheetDefinitions('phase4');
    }

    public function buildBundleZip(): string
    {
        return $this->templateSupport->buildZip(self::BUNDLE_README, 'phase4', 'master_import_phase4_tpl_');
    }

    public function buildWorkbookXlsx(int $outletId): string
    {
        return $this->templateSupport->buildXlsx(
            'Master Import Phase 4 — Profil Gaji / Salary Profiles',
            'Satu profil per karyawan. Karyawan harus sudah ada dari Fase 3.',
            'One profile per employee. Employees must exist from Phase 3.',
            'phase4',
            $outletId,
        );
    }
}
