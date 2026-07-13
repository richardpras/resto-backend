<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Employee;
use App\Modules\Imports\Services\Phase4MasterImportTemplateService;
use App\Modules\Imports\Support\CsvTableParser;
use App\Modules\Imports\Support\ImportTemplateSchema;
use App\Modules\Imports\Support\XlsxWorkbookParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\BugReportTestFixture;
use Tests\TestCase;

class MasterImportXlsxTemplateTest extends TestCase
{
    use BugReportTestFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_phase4_xlsx_template_requires_outlet_id(): void
    {
        $outlet = $this->createOutlet();
        $user = $this->createUserWithPermission('settings.manage', $outlet);
        Passport::actingAs($user);

        $this->get('/api/v1/imports/phase4/template-xlsx')
            ->assertStatus(422);

        $this->get('/api/v1/imports/phase4/template-xlsx?outletId='.$outlet->id)
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_phase1_xlsx_template_download(): void
    {
        $outlet = $this->createOutlet();
        $user = $this->createUserWithPermission('settings.manage', $outlet);
        Passport::actingAs($user);

        $this->get('/api/v1/imports/phase1/template-xlsx?outletId='.$outlet->id)
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_generated_xlsx_parses_bilingual_headers(): void
    {
        $outlet = $this->createOutlet();
        Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-001',
            'full_name' => 'Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $path = app(Phase4MasterImportTemplateService::class)->buildWorkbookXlsx($outlet->id);
        $sheets = XlsxWorkbookParser::extractSheets(
            new UploadedFile($path, 'phase4.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true),
        );

        $this->assertArrayHasKey('17_employee_salary_profiles.csv', $sheets);

        $specs = ImportTemplateSchema::columnSpecsForFilename('phase4', '17_employee_salary_profiles.csv');
        $rows = CsvTableParser::parse($sheets['17_employee_salary_profiles.csv'], $specs);
        $this->assertNotEmpty($rows);
        $this->assertArrayHasKey('employee_no', $rows[0]['data']);

        @unlink($path);
    }

    private function createOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'XLSX Template Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'xlsx-'.uniqid('', true),
        ]);
    }
}
