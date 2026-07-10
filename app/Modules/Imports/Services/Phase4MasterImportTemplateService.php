<?php

namespace App\Modules\Imports\Services;

use App\Modules\Imports\Support\CsvTableParser;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ZipArchive;

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
- One salary profile per employee; existing profiles are updated.
- overtime_rate_type: fixed_hourly | multiplier_hourly_salary
- When attendance_deduction_enabled is 1, attendance_deduction_per_day is required.

Upload ZIP or XLSX in Settings → Master Import (Phase 4), preview first, then commit.
TXT;

    /** @return array<string, array{headers: list<string>, examples: list<array<string, string>>}> */
    public static function sheetDefinitions(): array
    {
        return [
            '17_employee_salary_profiles.csv' => [
                'headers' => [
                    'employee_no',
                    'basic_salary',
                    'default_allowance',
                    'default_deduction',
                    'overtime_rate_type',
                    'overtime_rate_value',
                    'unpaid_leave_deduction_enabled',
                    'attendance_deduction_enabled',
                    'attendance_deduction_per_day',
                ],
                'examples' => [
                    [
                        'employee_no' => 'EMP-001',
                        'basic_salary' => '5000000',
                        'default_allowance' => '500000',
                        'default_deduction' => '100000',
                        'overtime_rate_type' => 'fixed_hourly',
                        'overtime_rate_value' => '25000',
                        'unpaid_leave_deduction_enabled' => '1',
                        'attendance_deduction_enabled' => '0',
                        'attendance_deduction_per_day' => '',
                    ],
                ],
            ],
        ];
    }

    public function buildBundleZip(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'master_import_phase4_tpl_');
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

    public function buildWorkbookXlsx(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'master_import_phase4_xlsx_');
        if ($tmp === false) {
            abort(500, 'Unable to create template workbook.');
        }

        $xlsxPath = $tmp.'.xlsx';
        @unlink($tmp);

        $spreadsheet = new Spreadsheet;
        $sheetIndex = 0;

        foreach (self::sheetDefinitions() as $filename => $definition) {
            $sheet = $sheetIndex === 0
                ? $spreadsheet->getActiveSheet()
                : $spreadsheet->createSheet($sheetIndex);

            $sheet->setTitle(str_replace('.csv', '', $filename));

            $headers = $definition['headers'];
            foreach ($headers as $colIndex => $header) {
                $sheet->setCellValue([$colIndex + 1, 1], $header);
            }

            foreach ($definition['examples'] as $rowIndex => $example) {
                foreach ($headers as $colIndex => $header) {
                    $sheet->setCellValue([$colIndex + 1, $rowIndex + 2], $example[$header] ?? '');
                }
            }

            $sheetIndex++;
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($xlsxPath);

        return $xlsxPath;
    }
}
