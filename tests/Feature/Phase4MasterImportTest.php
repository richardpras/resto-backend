<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Employee;
use App\Modules\Imports\Services\Phase4MasterImportService;
use App\Modules\Imports\Services\Phase4MasterImportTemplateService;
use App\Modules\Imports\Support\CsvTableParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\BugReportTestFixture;
use Tests\TestCase;
use ZipArchive;

class Phase4MasterImportTest extends TestCase
{
    use BugReportTestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_phase4_template_downloads(): void
    {
        $outlet = $this->createOutlet();
        $user = $this->createUserWithPermission('settings.manage', $outlet);
        Passport::actingAs($user);

        $this->get('/api/v1/imports/phase4/template')
            ->assertOk()
            ->assertHeader('content-disposition');

        $this->get('/api/v1/imports/phase4/template-xlsx')
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_phase4_bundle_preview_and_commit_from_zip(): void
    {
        $outlet = $this->createOutlet();
        $user = $this->createUserWithPermission('payroll.manage', $outlet);
        $employee = $this->seedEmployee($outlet);

        $service = app(Phase4MasterImportService::class);

        $preview = $service->importBundle($user, [
            'outletId' => $outlet->id,
            'preview' => true,
            'file' => new UploadedFile($this->buildSampleZipOnDisk($employee->employee_no), 'phase4.zip', 'application/zip', null, true),
        ]);
        $this->assertTrue($preview['canCommit']);
        $this->assertSame(1, $preview['sections']['employee_salary_profiles']['created']);

        $commit = $service->importBundle($user, [
            'outletId' => $outlet->id,
            'preview' => false,
            'file' => new UploadedFile($this->buildSampleZipOnDisk($employee->employee_no), 'phase4.zip', 'application/zip', null, true),
        ]);
        $this->assertSame(0, $commit['errorCount']);

        $profile = EmployeeSalaryProfile::query()->where('employee_id', $employee->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame(5000000.0, (float) $profile->basic_salary);
        $this->assertSame(500000.0, (float) $profile->default_allowance);
    }

    public function test_phase4_bundle_commit_from_xlsx(): void
    {
        $outlet = $this->createOutlet();
        $user = $this->createUserWithPermission('payroll.manage', $outlet);
        $employee = $this->seedEmployee($outlet);

        $xlsxPath = app(Phase4MasterImportTemplateService::class)->buildWorkbookXlsx();
        $service = app(Phase4MasterImportService::class);

        $commit = $service->importBundle($user, [
            'outletId' => $outlet->id,
            'preview' => false,
            'file' => new UploadedFile($xlsxPath, 'phase4.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        ]);
        $this->assertSame(0, $commit['errorCount']);

        $profile = EmployeeSalaryProfile::query()->where('employee_id', $employee->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame(5000000.0, (float) $profile->basic_salary);

        @unlink($xlsxPath);
    }

    public function test_phase4_employee_salary_profiles_csv_endpoint(): void
    {
        $outlet = $this->createOutlet();
        $user = $this->createUserWithPermission('employees.manage', $outlet);
        $employee = $this->seedEmployee($outlet);
        Passport::actingAs($user);

        $definitions = Phase4MasterImportTemplateService::sheetDefinitions();
        $csv = CsvTableParser::toCsv(
            $definitions['17_employee_salary_profiles.csv']['headers'],
            [[
                'employee_no' => $employee->employee_no,
                'basic_salary' => '4200000',
                'default_allowance' => '300000',
                'default_deduction' => '50000',
                'overtime_rate_type' => 'multiplier_hourly_salary',
                'overtime_rate_value' => '1.5',
                'unpaid_leave_deduction_enabled' => '1',
                'attendance_deduction_enabled' => '0',
                'attendance_deduction_per_day' => '',
            ]],
        );

        $this->postJson('/api/v1/imports/phase4/employee_salary_profiles', [
            'outletId' => $outlet->id,
            'preview' => false,
            'csv' => $csv,
        ])->assertOk();

        $profile = EmployeeSalaryProfile::query()->where('employee_id', $employee->id)->first();
        $this->assertNotNull($profile);
        $this->assertSame(4200000.0, (float) $profile->basic_salary);
        $this->assertSame('multiplier_hourly_salary', $profile->overtime_rate_type);
    }

    private function createOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Phase4 Import Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p4-'.uniqid('', true),
        ]);
    }

    private function seedEmployee(Outlet $outlet): Employee
    {
        return Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-001',
            'full_name' => 'Payroll Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);
    }

    private function buildSampleZipOnDisk(string $employeeNo): string
    {
        $definitions = Phase4MasterImportTemplateService::sheetDefinitions();
        $tmp = tempnam(sys_get_temp_dir(), 'phase4_import_test_');
        $zipPath = $tmp.'.zip';
        @unlink($tmp);

        $zip = new ZipArchive;
        $zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $zip->addFromString('17_employee_salary_profiles.csv', CsvTableParser::toCsv(
            $definitions['17_employee_salary_profiles.csv']['headers'],
            [[
                'employee_no' => $employeeNo,
                'basic_salary' => '5000000',
                'default_allowance' => '500000',
                'default_deduction' => '100000',
                'overtime_rate_type' => 'fixed_hourly',
                'overtime_rate_value' => '25000',
                'unpaid_leave_deduction_enabled' => '1',
                'attendance_deduction_enabled' => '0',
                'attendance_deduction_per_day' => '',
            ]],
        ));
        $zip->close();

        return $zipPath;
    }
}
