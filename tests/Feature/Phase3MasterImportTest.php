<?php

namespace Tests\Feature;

use App\Models\Modules\Loyalty\Domain\LoyaltyAccount;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Department;
use App\Models\Modules\UserManagement\Domain\Employee;
use App\Models\Modules\UserManagement\Domain\Position;
use App\Modules\Imports\Services\Phase3MasterImportService;
use App\Modules\Imports\Services\Phase3MasterImportTemplateService;
use App\Modules\Imports\Support\ImportTemplateSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\BugReportTestFixture;
use Tests\Concerns\BuildsMasterImportTestZip;
use Tests\TestCase;
use ZipArchive;

class Phase3MasterImportTest extends TestCase
{
    use BugReportTestFixture;
    use BuildsMasterImportTestZip;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_phase3_template_download(): void
    {
        $outlet = $this->createOutlet();
        $user = $this->createUserWithPermission('settings.manage', $outlet);
        Passport::actingAs($user);

        $this->get('/api/v1/imports/phase3/template')
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_phase3_bundle_preview_and_commit(): void
    {
        $outlet = $this->createOutlet();
        $user = $this->createUserWithPermission('employees.manage', $outlet);

        LoyaltyAccount::query()->create([
            'outlet_id' => $outlet->id,
            'customer_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'global_customer_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'import_code' => 'CUST_001',
            'name' => 'Budi',
            'phone' => '081234567890',
            'points_balance' => 0,
            'lifetime_points_earned' => 0,
            'lifetime_points_redeemed' => 0,
            'lifetime_spend' => 0,
            'lifetime_visits' => 0,
            'last_activity_at' => now(),
        ]);

        $service = app(Phase3MasterImportService::class);

        $preview = $service->importBundle($user, [
            'outletId' => $outlet->id,
            'preview' => true,
            'file' => new UploadedFile($this->buildSampleZipOnDisk(), 'phase3.zip', 'application/zip', null, true),
        ]);
        $this->assertTrue($preview['canCommit']);
        $this->assertSame(1, $preview['sections']['departments']['created']);

        $commit = $service->importBundle($user, [
            'outletId' => $outlet->id,
            'preview' => false,
            'file' => new UploadedFile($this->buildSampleZipOnDisk(), 'phase3.zip', 'application/zip', null, true),
        ]);
        $this->assertSame(0, $commit['errorCount']);

        $this->assertDatabaseHas('departments', ['outlet_id' => $outlet->id, 'code' => 'OPS']);
        $this->assertDatabaseHas('positions', ['outlet_id' => $outlet->id, 'code' => 'WAITER']);
        $this->assertDatabaseHas('employees', ['outlet_id' => $outlet->id, 'employee_no' => 'EMP-001']);

        $customer = LoyaltyAccount::query()->where('import_code', 'CUST_001')->first();
        $this->assertNotNull($customer);
        $this->assertGreaterThanOrEqual(500, (int) $customer->fresh()->points_balance);

        $department = Department::query()->where('code', 'OPS')->first();
        $position = Position::query()->where('code', 'WAITER')->first();
        $employee = Employee::query()->where('employee_no', 'EMP-001')->first();
        $this->assertNotNull($employee);
        $this->assertSame((int) $department->id, (int) $employee->department_id);
        $this->assertSame((int) $position->id, (int) $employee->position_id);
    }

    public function test_phase3_departments_csv_endpoint(): void
    {
        $outlet = $this->createOutlet();
        $user = $this->createUserWithPermission('payroll.manage', $outlet);
        Passport::actingAs($user);

        $csv = ImportTemplateSchema::findSheet('phase3', '13_departments.csv')
            ?->toCsvFromFieldExamples([[
                'code' => 'HR', 'name' => 'Human Resources', 'description' => '', 'active' => '1',
            ]]) ?? '';

        $this->postJson('/api/v1/imports/phase3/departments', [
            'outletId' => $outlet->id,
            'preview' => false,
            'csv' => $csv,
        ])->assertOk();

        $this->assertDatabaseHas('departments', [
            'outlet_id' => $outlet->id,
            'code' => 'HR',
        ]);
    }

    private function createOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Phase3 Import Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p3-'.uniqid('', true),
        ]);
    }

    private function buildSampleZipOnDisk(): string
    {
        return $this->buildPhaseZip('phase3', [
            '13_departments.csv' => [['code' => 'OPS', 'name' => 'Operations', 'description' => '', 'active' => '1']],
            '14_positions.csv' => [[
                'code' => 'WAITER', 'name' => 'Waiter', 'department_code' => 'OPS',
                'description' => '', 'sort_order' => '10', 'active' => '1',
            ]],
            '15_employees.csv' => [[
                'employee_no' => 'EMP-001', 'full_name' => 'Andi', 'email' => '', 'phone' => '08111',
                'gender' => '', 'birth_date' => '', 'hire_date' => '2024-01-01', 'status' => 'active',
                'department_code' => 'OPS', 'position_code' => 'WAITER', 'salary_type' => 'monthly',
                'base_salary' => '4000000', 'overtime_rate' => '0', 'notes' => '',
            ]],
            '16_opening_loyalty_points.csv' => [['customer_code' => 'CUST_001', 'points' => '500', 'memo' => 'Opening']],
        ]);
    }
}
