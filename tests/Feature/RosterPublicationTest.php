<?php

namespace Tests\Feature;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\HrmApiFixture;
use Tests\TestCase;

class RosterPublicationTest extends TestCase
{
    use HrmApiFixture;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_publish_draft_rosters_in_range(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shift, $outlet] = $this->seedFixtures();

        foreach (['2026-07-01', '2026-07-02', '2026-07-03'] as $date) {
            EmployeeRoster::query()->create([
                'outlet_id' => $outlet->id,
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'roster_date' => $date,
                'status' => EmployeeRoster::STATUS_DRAFT,
            ]);
        }

        $this->postJson('/api/v1/rosters/publish', [
            'outletId' => $outlet->id,
            'fromDate' => '2026-07-01',
            'toDate' => '2026-07-31',
        ])->assertOk()
            ->assertJsonPath('data.published', 3);

        $this->assertSame(
            3,
            EmployeeRoster::query()
                ->where('status', EmployeeRoster::STATUS_PUBLISHED)
                ->whereNotNull('published_at')
                ->count(),
        );
    }

    public function test_copy_schedule_week_to_week(): void
    {
        $this->actingAsHrmApiAdministrator();
        [$employee, $shift, $outlet] = $this->seedFixtures();

        foreach (range(1, 7) as $day) {
            EmployeeRoster::query()->create([
                'outlet_id' => $outlet->id,
                'employee_id' => $employee->id,
                'shift_id' => $shift->id,
                'roster_date' => sprintf('2026-07-%02d', $day),
                'status' => EmployeeRoster::STATUS_DRAFT,
            ]);
        }

        $this->postJson('/api/v1/rosters/copy', [
            'outletId' => $outlet->id,
            'sourceFrom' => '2026-07-01',
            'sourceTo' => '2026-07-07',
            'destFrom' => '2026-07-08',
            'destTo' => '2026-07-14',
        ])->assertOk()
            ->assertJsonPath('data.copied', 7);

        $this->assertSame(14, EmployeeRoster::query()->where('employee_id', $employee->id)->count());
        $this->assertTrue(
            EmployeeRoster::query()
                ->where('employee_id', $employee->id)
                ->where('roster_date', '2026-07-14')
                ->exists(),
        );
    }

    /**
     * @return array{0: Employee, 1: Shift, 2: Outlet}
     */
    private function seedFixtures(): array
    {
        $outlet = Outlet::query()->create([
            'name' => 'Pub Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'pub-out',
        ]);

        $employee = Employee::query()->create([
            'outlet_id' => $outlet->id,
            'employee_no' => 'EMP-PUB',
            'full_name' => 'Publish Worker',
            'position' => 'Staff',
            'base_salary' => 0,
            'status' => 'active',
        ]);

        $shift = Shift::query()->create([
            'tenant_id' => 1,
            'code' => 'PUB-SHIFT',
            'name' => 'Morning Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
            'late_tolerance_minutes' => 10,
            'overtime_after_minutes' => 0,
            'active' => true,
        ]);

        return [$employee, $shift, $outlet];
    }
}
