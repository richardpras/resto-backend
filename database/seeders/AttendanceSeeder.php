<?php

namespace Database\Seeders;

use App\Models\Modules\HR\Domain\Attendance;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\Shift;
use Carbon\Carbon;
use Database\Seeders\Concerns\PayrollSeederData;
use Illuminate\Database\Seeder;

class AttendanceSeeder extends Seeder
{
    use PayrollSeederData;

    public function run(): void
    {
        $shiftMap = $this->ensureShifts();
        $workingDates = $this->currentMonthWorkingDates(22);

        $employees = Employee::query()
            ->where('status', 'active')
            ->get()
            ->keyBy('employee_no');

        foreach ($this->payrollEmployees() as $seedEmp) {
            $employee = $employees->get($seedEmp['employee_no']);
            if ($employee === null) {
                continue;
            }

            $pattern = $this->attendancePattern($seedEmp['employee_no']);
            foreach ($workingDates as $index => $date) {
                $status = $pattern[$index % count($pattern)];
                $shiftCode = $seedEmp['outlet'] === 'Branch 1' ? 'SHIFT-BR-DAY' : 'SHIFT-MAIN-DAY';
                $shiftId = $shiftMap[$shiftCode] ?? null;

                $checkIn = null;
                $checkOut = null;
                if ($status !== 'absent') {
                    [$checkIn, $checkOut] = $this->checkInOutForStatus($date, $status);
                }

                Attendance::query()->updateOrCreate(
                    ['sync_key' => sprintf('seed-att-%s-%s', strtolower($seedEmp['employee_no']), $date)],
                    [
                        'employee_id' => $employee->id,
                        'shift_id' => $shiftId,
                        'attendance_date' => $date,
                        'check_in' => $checkIn,
                        'check_out' => $checkOut,
                        'source' => 'manual',
                        'status' => $status,
                        'notes' => $status === 'absent' ? 'Tidak masuk kerja' : null,
                        'created_by' => null,
                        'updated_by' => null,
                    ],
                );
            }
        }
    }

    /**
     * @return array<string, int>
     */
    private function ensureShifts(): array
    {
        $shifts = [
            ['code' => 'SHIFT-MAIN-DAY', 'name' => 'Main Day Shift', 'start_time' => '08:00:00', 'end_time' => '17:00:00'],
            ['code' => 'SHIFT-BR-DAY', 'name' => 'Branch Day Shift', 'start_time' => '09:00:00', 'end_time' => '18:00:00'],
        ];

        $map = [];
        foreach ($shifts as $row) {
            $shift = Shift::query()->updateOrCreate(
                ['code' => $row['code']],
                [
                    'tenant_id' => null,
                    'name' => $row['name'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                    'late_tolerance_minutes' => 10,
                    'overtime_after_minutes' => 0,
                    'active' => true,
                    'notes' => 'Seed payroll shift',
                    'created_by' => null,
                    'updated_by' => null,
                ],
            );
            $map[$row['code']] = (int) $shift->id;
        }

        return $map;
    }

    /**
     * @return array<int, string>
     */
    private function currentMonthWorkingDates(int $max): array
    {
        $cursor = Carbon::now()->startOfMonth();
        $end = Carbon::now()->endOfMonth();
        $dates = [];

        while ($cursor->lte($end) && count($dates) < $max) {
            if (! $cursor->isWeekend()) {
                $dates[] = $cursor->toDateString();
            }
            $cursor->addDay();
        }

        return $dates;
    }

    /**
     * @return array<int, string>
     */
    private function attendancePattern(string $employeeNo): array
    {
        if ($employeeNo === 'EMP-PAY-003') {
            return ['present', 'absent', 'late', 'absent', 'present', 'absent', 'present'];
        }

        if ($employeeNo === 'EMP-PAY-009') {
            return ['late', 'late', 'present', 'late', 'present', 'present', 'late'];
        }

        return ['present', 'present', 'late', 'present', 'present', 'present', 'present'];
    }

    /**
     * @return array{0:string,1:string}
     */
    private function checkInOutForStatus(string $date, string $status): array
    {
        if ($status === 'late') {
            return [$date.' 09:18:00', $date.' 17:10:00'];
        }

        return [$date.' 08:05:00', $date.' 17:03:00'];
    }
}
