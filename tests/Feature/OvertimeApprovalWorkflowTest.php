<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\AttendanceRecord;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\OvertimeDailySummary;
use App\Models\Modules\HR\Domain\OvertimeType;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class OvertimeApprovalWorkflowTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_approve_reject_cancel_workflow(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $type] = $this->seedFixtures();

        $create = $this->postJson('/api/v1/overtime-requests', [
            'employeeId' => $employee->id,
            'overtimeTypeId' => $type->id,
            'overtimeDate' => '2026-12-01',
            'startTime' => '18:00',
            'endTime' => '20:00',
        ])->assertCreated();

        $id = (int) $create->json('data.id');

        $this->patchJson('/api/v1/overtime-requests/'.$id.'/approve')->assertOk();

        $rejectReq = $this->postJson('/api/v1/overtime-requests', [
            'employeeId' => $employee->id,
            'overtimeTypeId' => $type->id,
            'overtimeDate' => '2026-12-02',
            'startTime' => '18:00',
            'endTime' => '19:00',
        ])->assertCreated();

        $this->patchJson('/api/v1/overtime-requests/'.$rejectReq->json('data.id').'/reject', [
            'rejectionReason' => 'Not needed',
        ])->assertOk();

        $this->patchJson('/api/v1/overtime-requests/'.$id.'/cancel')->assertOk();

        $summary = OvertimeDailySummary::query()
            ->where('employee_id', $employee->id)
            ->where('overtime_date', '2026-12-01')
            ->first();

        $this->assertNull($summary);
    }

    public function test_approve_does_not_modify_attendance(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $type] = $this->seedFixtures();

        $res = $this->postJson('/api/v1/overtime-requests', [
            'employeeId' => $employee->id,
            'overtimeTypeId' => $type->id,
            'overtimeDate' => '2026-12-05',
            'startTime' => '18:00',
            'endTime' => '21:00',
        ])->assertCreated();

        $this->patchJson('/api/v1/overtime-requests/'.$res->json('data.id').'/approve')->assertOk();

        $this->assertSame(0, AttendanceRecord::query()->where('employee_id', $employee->id)->count());
    }

    /**
     * @return array{0: Employee, 1: OvertimeType}
     */
    private function seedFixtures(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Flow OT',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'ot-flow',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-OT-FLOW',
            'full_name' => 'Flow Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $type = OvertimeType::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'emergency',
            'name' => 'Emergency',
            'multiplier' => 2.0,
            'is_active' => true,
        ]);

        return [$employee, $type];
    }
}
