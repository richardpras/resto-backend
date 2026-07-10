<?php

namespace App\Modules\Imports\Services;

use App\Modules\Imports\Support\CsvTableParser;
use ZipArchive;

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
- employee_no must be unique per outlet (used by attendance import).
- department_code / position_code link org structure.
- customer_code in sheet 16 must match import_code from customers.

Upload in Settings → Master Import (Phase 3), preview first, then commit.
TXT;

    /** @return array<string, array{headers: list<string>, examples: list<array<string, string>>}> */
    public static function sheetDefinitions(): array
    {
        return [
            '13_departments.csv' => [
                'headers' => ['code', 'name', 'description', 'active'],
                'examples' => [
                    [
                        'code' => 'OPS',
                        'name' => 'Operations',
                        'description' => '',
                        'active' => '1',
                    ],
                ],
            ],
            '14_positions.csv' => [
                'headers' => ['code', 'name', 'department_code', 'description', 'sort_order', 'active'],
                'examples' => [
                    [
                        'code' => 'WAITER',
                        'name' => 'Waiter',
                        'department_code' => 'OPS',
                        'description' => '',
                        'sort_order' => '10',
                        'active' => '1',
                    ],
                ],
            ],
            '15_employees.csv' => [
                'headers' => [
                    'employee_no', 'full_name', 'email', 'phone', 'gender', 'birth_date', 'hire_date',
                    'status', 'department_code', 'position_code', 'salary_type', 'base_salary', 'overtime_rate', 'notes',
                ],
                'examples' => [
                    [
                        'employee_no' => 'EMP-001',
                        'full_name' => 'Andi Pratama',
                        'email' => 'andi@example.com',
                        'phone' => '081234567890',
                        'gender' => 'male',
                        'birth_date' => '1995-03-10',
                        'hire_date' => '2024-01-01',
                        'status' => 'active',
                        'department_code' => 'OPS',
                        'position_code' => 'WAITER',
                        'salary_type' => 'monthly',
                        'base_salary' => '4500000',
                        'overtime_rate' => '0',
                        'notes' => '',
                    ],
                ],
            ],
            '16_opening_loyalty_points.csv' => [
                'headers' => ['customer_code', 'points', 'memo'],
                'examples' => [
                    ['customer_code' => 'CUST_001', 'points' => '500', 'memo' => 'Opening balance'],
                ],
            ],
        ];
    }

    public function buildBundleZip(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'master_import_phase3_tpl_');
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
