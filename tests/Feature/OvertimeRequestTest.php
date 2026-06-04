<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\OvertimeRequest;
use App\Models\Modules\HR\Domain\OvertimeType;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\HR\Services\OvertimeRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class OvertimeRequestTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_create_request_calculates_hours(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $type] = $this->seedFixtures();

        $this->postJson('/api/v1/overtime-requests', [
            'employeeId' => $employee->id,
            'overtimeTypeId' => $type->id,
            'overtimeDate' => '2026-11-01',
            'startTime' => '18:00',
            'endTime' => '21:00',
            'reason' => 'Closing shift',
        ])->assertCreated()
            ->assertJsonPath('data.totalMinutes', 180)
            ->assertJsonPath('data.totalHours', 3);
    }

    public function test_invalid_end_before_start_rejected(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $type] = $this->seedFixtures();

        $this->postJson('/api/v1/overtime-requests', [
            'employeeId' => $employee->id,
            'overtimeTypeId' => $type->id,
            'overtimeDate' => '2026-11-02',
            'startTime' => '20:00',
            'endTime' => '18:00',
        ])->assertStatus(422);
    }

    public function test_overlap_with_approved_rejected(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $type] = $this->seedFixtures();

        OvertimeRequest::query()->create([
            'outlet_id' => $employee->outlet_id,
            'employee_id' => $employee->id,
            'overtime_type_id' => $type->id,
            'overtime_date' => '2026-11-05',
            'start_time' => '18:00:00',
            'end_time' => '22:00:00',
            'total_minutes' => 240,
            'total_hours' => 4,
            'status' => OvertimeRequest::STATUS_APPROVED,
        ]);

        $this->postJson('/api/v1/overtime-requests', [
            'employeeId' => $employee->id,
            'overtimeTypeId' => $type->id,
            'overtimeDate' => '2026-11-05',
            'startTime' => '20:00',
            'endTime' => '23:00',
        ])->assertStatus(422);
    }

    public function test_duration_calculation_service(): void
    {
        $calc = app(OvertimeRequestService::class)->calculateDuration('17:30', '19:00');
        $this->assertSame(90, $calc['minutes']);
        $this->assertEquals(1.5, $calc['hours']);
    }

    /**
     * @return array{0: Employee, 1: OvertimeType}
     */
    private function seedFixtures(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Req OT',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'ot-req',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-OT-01',
            'full_name' => 'OT Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $type = OvertimeType::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'regular',
            'name' => 'Regular OT',
            'multiplier' => 1.5,
            'is_active' => true,
        ]);

        return [$employee, $type];
    }
}
