<?php

namespace Tests\Unit\Imports;

use App\Modules\Imports\Support\CsvTableParser;
use App\Modules\Imports\Support\ImportColumnSpec;
use App\Modules\Imports\Support\ImportHeaderAliasResolver;
use App\Modules\Imports\Support\ImportTemplateSchema;
use PHPUnit\Framework\TestCase;

class ImportHeaderAliasResolverTest extends TestCase
{
    public function test_resolves_bilingual_header_with_field_in_parentheses(): void
    {
        $spec = new ImportColumnSpec('basic_salary', 'Gaji Pokok', 'Basic Salary', true);

        $resolved = ImportHeaderAliasResolver::resolve($spec->bilingualHeader(), [$spec]);

        $this->assertSame('basic_salary', $resolved);
    }

    public function test_resolves_legacy_technical_header(): void
    {
        $spec = new ImportColumnSpec('employee_no', 'Nomor Karyawan', 'Employee No', true);

        $resolved = ImportHeaderAliasResolver::resolve('employee_no', [$spec]);

        $this->assertSame('employee_no', $resolved);
    }

    public function test_parser_maps_bilingual_csv_rows_to_internal_fields(): void
    {
        $specs = ImportTemplateSchema::columnSpecsForFilename('phase4', '17_employee_salary_profiles.csv');
        $this->assertNotNull($specs);

        $headers = array_map(static fn (ImportColumnSpec $col) => $col->bilingualHeader(), $specs);
        $csv = CsvTableParser::toCsv($headers, [[
            $headers[0] => 'EMP-001',
            $headers[1] => '5000000',
            $headers[2] => '500000',
            $headers[3] => '100000',
            $headers[4] => 'fixed_hourly',
            $headers[5] => '25000',
            $headers[6] => '1',
            $headers[7] => '0',
            $headers[8] => '',
        ]]);

        $rows = CsvTableParser::parse($csv, $specs);
        $this->assertCount(1, $rows);
        $this->assertSame('EMP-001', $rows[0]['data']['employee_no']);
        $this->assertSame('5000000', $rows[0]['data']['basic_salary']);
    }
}
