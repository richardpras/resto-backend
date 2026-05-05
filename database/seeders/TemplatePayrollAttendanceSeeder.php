<?php

namespace Database\Seeders;

use App\Models\Modules\HR\Domain\Attendance;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\Shift;
use Database\Seeders\Concerns\LoadsTemplatePayrollData;
use Illuminate\Database\Seeder;

class TemplatePayrollAttendanceSeeder extends Seeder
{
    use LoadsTemplatePayrollData;

    public function run(): void
    {
        $data = $this->templatePayrollData();

        $employeesByLegacy = Employee::query()
            ->get()
            ->keyBy(fn (Employee $employee) => strtolower($employee->employee_no));

        $templateShiftByEmployeeDate = [];
        foreach ($data['shifts'] as $shiftRow) {
            $templateShiftByEmployeeDate[$shiftRow['employeeId'].'|'.$shiftRow['date']] = $shiftRow;
        }

        foreach ($data['attendance'] as $row) {
            /** @var Employee|null $employee */
            $employee = $employeesByLegacy->get(strtolower($row['employeeId']));
            if ($employee === null) {
                continue;
            }

            $shiftTemplate = $templateShiftByEmployeeDate[$row['employeeId'].'|'.$row['date']] ?? null;
            $shiftId = null;
            if (is_array($shiftTemplate)) {
                $shift = Shift::query()
                    ->where('start_time', $this->normalizeTime($shiftTemplate['startTime']))
                    ->where('end_time', $this->normalizeTime($shiftTemplate['endTime']))
                    ->first();
                $shiftId = $shift?->id;
            }

            Attendance::query()->updateOrCreate(
                ['sync_key' => 'tpl-att-'.$row['id']],
                [
                    'employee_id' => $employee->id,
                    'shift_id' => $shiftId,
                    'attendance_date' => $row['date'],
                    'check_in' => isset($row['checkIn']) ? $row['date'].' '.$this->normalizeTime($row['checkIn']) : null,
                    'check_out' => isset($row['checkOut']) ? $row['date'].' '.$this->normalizeTime($row['checkOut']) : null,
                    'source' => 'manual',
                    'status' => $row['status'],
                    'notes' => $row['notes'] ?? null,
                    'created_by' => null,
                    'updated_by' => null,
                ],
            );
        }
    }

    private function normalizeTime(string $value): string
    {
        return strlen($value) === 5 ? $value.':00' : $value;
    }
}
