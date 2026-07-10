<?php

namespace App\Modules\Imports\Services;

use App\Modules\Imports\Support\CsvTableParser;
use ZipArchive;

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
- COA account codes are globally unique.
- Opening balance lines must balance (total debit = total credit).
- Payment method codes: cash, manual_qris, gateway_qris, gateway_ewallet, manual_transfer
- Customer/member codes are stable import keys per outlet.

Upload in Settings → Master Import (Phase 2), preview first, then commit.
TXT;

    /** @return array<string, array{headers: list<string>, examples: list<array<string, string>>}> */
    public static function sheetDefinitions(): array
    {
        return [
            '08_chart_of_accounts.csv' => [
                'headers' => ['code', 'name', 'type', 'subtype', 'category', 'parent_code', 'description', 'active'],
                'examples' => [
                    [
                        'code' => '1100',
                        'name' => 'Cash',
                        'type' => 'asset',
                        'subtype' => 'current_asset',
                        'category' => 'cash_bank',
                        'parent_code' => '',
                        'description' => '',
                        'active' => '1',
                    ],
                ],
            ],
            '09_opening_balances.csv' => [
                'headers' => ['account_code', 'debit', 'credit', 'memo', 'journal_date'],
                'examples' => [
                    [
                        'account_code' => '1100',
                        'debit' => '50000000',
                        'credit' => '0',
                        'memo' => 'Opening cash',
                        'journal_date' => '',
                    ],
                    [
                        'account_code' => '3100',
                        'debit' => '0',
                        'credit' => '50000000',
                        'memo' => 'Opening equity',
                        'journal_date' => '',
                    ],
                ],
            ],
            '10_customers.csv' => [
                'headers' => ['code', 'name', 'phone', 'email'],
                'examples' => [
                    [
                        'code' => 'CUST_001',
                        'name' => 'Budi Santoso',
                        'phone' => '081234567890',
                        'email' => 'budi@example.com',
                    ],
                ],
            ],
            '11_members.csv' => [
                'headers' => ['code', 'full_name', 'phone', 'email', 'birth_date', 'gender', 'status', 'customer_code', 'notes'],
                'examples' => [
                    [
                        'code' => 'MEM_001',
                        'full_name' => 'Budi Santoso',
                        'phone' => '081234567890',
                        'email' => 'budi@example.com',
                        'birth_date' => '1990-01-15',
                        'gender' => 'male',
                        'status' => 'active',
                        'customer_code' => 'CUST_001',
                        'notes' => '',
                    ],
                ],
            ],
            '12_outlet_payment_methods.csv' => [
                'headers' => ['payment_method_code', 'enabled', 'is_default', 'display_order', 'provider', 'chart_account_code', 'instructions'],
                'examples' => [
                    [
                        'payment_method_code' => 'cash',
                        'enabled' => '1',
                        'is_default' => '1',
                        'display_order' => '10',
                        'provider' => '',
                        'chart_account_code' => '1100',
                        'instructions' => '',
                    ],
                    [
                        'payment_method_code' => 'manual_qris',
                        'enabled' => '1',
                        'is_default' => '0',
                        'display_order' => '20',
                        'provider' => 'manual',
                        'chart_account_code' => '1120',
                        'instructions' => 'Scan QRIS outlet lalu konfirmasi.',
                    ],
                ],
            ],
        ];
    }

    public function buildBundleZip(): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'master_import_phase2_tpl_');
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
