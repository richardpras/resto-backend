<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\AttendanceImportBatch;
use App\Models\Modules\HR\Domain\AttendanceRecord;
use App\Models\Modules\HR\Domain\Employee;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class AttendanceImportService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
        private readonly AttendanceMatchingService $matching,
    ) {}

    /**
     * @return array{preview: list<array<string, mixed>>, created: int, skipped: int, batch: ?AttendanceImportBatch}
     */
    public function importCsv(?User $user, array $payload): array
    {
        $outletId = (int) ($payload['outletId'] ?? 0);
        abort_if($outletId < 1, 422, 'outletId is required.');

        $csv = (string) ($payload['csv'] ?? '');
        abort_if(trim($csv) === '', 422, 'CSV content is required.');

        $employeeCodeColumn = (string) ($payload['employeeCodeColumn'] ?? 'employee_code');
        $timestampColumn = (string) ($payload['timestampColumn'] ?? 'timestamp');
        $dryRun = (bool) ($payload['preview'] ?? $payload['dryRun'] ?? false);
        $overwrite = (bool) ($payload['overwriteExisting'] ?? false);

        $rows = $this->parseCsv($csv, $employeeCodeColumn, $timestampColumn);
        $grouped = $this->groupPunches($rows);

        $preview = [];
        $created = 0;
        $skipped = 0;
        $batch = null;

        if (! $dryRun) {
            $batch = AttendanceImportBatch::query()->create([
                'outlet_id' => $outletId,
                'filename' => (string) ($payload['filename'] ?? 'import.csv'),
                'imported_rows' => 0,
                'imported_at' => now(),
                'created_by' => $user?->id,
            ]);
        }

        foreach ($grouped as $group) {
            $employee = Employee::query()
                ->where('employee_no', $group['employee_code'])
                ->where('outlet_id', $outletId)
                ->first();

            if ($employee === null) {
                $skipped++;

                continue;
            }

            try {
                $this->employeeMaster->assertEmployeeOutletAllowed($user, $employee);
            } catch (ValidationException) {
                $skipped++;

                continue;
            }

            $built = $this->buildRecordPayload($employee, $group['date'], $group['timestamps']);

            $preview[] = [
                'employeeCode' => $group['employee_code'],
                'employeeName' => $employee->full_name,
                'date' => $group['date'],
                'clockIn' => $built['clock_in']?->format('Y-m-d H:i:s'),
                'clockOut' => $built['clock_out']?->format('Y-m-d H:i:s'),
                'status' => $built['status'],
                'shiftName' => $built['shift_name'],
            ];

            if ($dryRun) {
                continue;
            }

            $existing = AttendanceRecord::query()
                ->where('employee_id', $employee->id)
                ->where('attendance_date', $group['date'])
                ->first();

            if ($existing !== null && ! $overwrite) {
                $skipped++;

                continue;
            }

            if ($existing !== null && $overwrite) {
                $existing->fill($built['attributes'])->save();
                $created++;
            } else {
                AttendanceRecord::query()->create(array_merge($built['attributes'], [
                    'import_batch_id' => $batch?->id,
                ]));
                $created++;
            }
        }

        if ($batch !== null) {
            $batch->update(['imported_rows' => $created]);
        }

        return [
            'preview' => $preview,
            'created' => $created,
            'skipped' => $skipped,
            'batch' => $batch,
        ];
    }

    /**
     * @return list<array{employee_code: string, timestamp: Carbon}>
     */
    private function parseCsv(string $csv, string $employeeCodeColumn, string $timestampColumn): array
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($csv)) ?: [];
        if ($lines === []) {
            return [];
        }

        $headerLine = array_shift($lines);
        $headers = array_map(static fn ($h) => strtolower(trim($h)), str_getcsv($headerLine));
        $codeIdx = array_search(strtolower($employeeCodeColumn), $headers, true);
        $tsIdx = array_search(strtolower($timestampColumn), $headers, true);

        if ($codeIdx === false || $tsIdx === false) {
            throw ValidationException::withMessages([
                'csv' => ["CSV must include columns: {$employeeCodeColumn}, {$timestampColumn}."],
            ]);
        }

        $parsed = [];
        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $cols = str_getcsv($line);
            $code = trim((string) ($cols[$codeIdx] ?? ''));
            $tsRaw = trim((string) ($cols[$tsIdx] ?? ''));
            if ($code === '' || $tsRaw === '') {
                continue;
            }
            try {
                $parsed[] = [
                    'employee_code' => $code,
                    'timestamp' => Carbon::parse($tsRaw),
                ];
            } catch (\Throwable) {
                throw ValidationException::withMessages([
                    'csv' => ["Invalid timestamp: {$tsRaw}"],
                ]);
            }
        }

        return $parsed;
    }

    /**
     * @param  list<array{employee_code: string, timestamp: Carbon}>  $rows
     * @return Collection<int, array{employee_code: string, date: string, timestamps: list<Carbon>}>
     */
    private function groupPunches(array $rows): Collection
    {
        $groups = collect($rows)->groupBy(
            fn (array $row) => $row['employee_code'].'|'.$row['timestamp']->toDateString(),
        );

        return $groups->map(function (Collection $items, string $key) {
            [$code, $date] = explode('|', $key, 2);

            return [
                'employee_code' => $code,
                'date' => $date,
                'timestamps' => $items->pluck('timestamp')->sort()->values()->all(),
            ];
        })->values();
    }

    /**
     * @param  list<Carbon>  $timestamps
     * @return array{attributes: array<string, mixed>, clock_in: ?Carbon, clock_out: ?Carbon, status: string, shift_name: ?string}
     */
    private function buildRecordPayload(Employee $employee, string $date, array $timestamps): array
    {
        $clockIn = $timestamps !== [] ? $timestamps[0] : null;
        $clockOut = count($timestamps) > 1 ? $timestamps[count($timestamps) - 1] : null;

        $match = $this->matching->resolveRosterAndShift((int) $employee->id, $date);
        $calc = $this->matching->calculateStatusAndWorkedMinutes($clockIn, $clockOut, $match['shift'], $date);

        return [
            'clock_in' => $clockIn,
            'clock_out' => $clockOut,
            'status' => $calc['status'],
            'shift_name' => $match['shift']?->name,
            'attributes' => [
                'outlet_id' => (int) $employee->outlet_id,
                'employee_id' => (int) $employee->id,
                'roster_id' => $match['roster']?->id,
                'shift_id' => $match['shift']?->id ?? $match['roster']?->shift_id,
                'attendance_date' => $date,
                'clock_in' => $clockIn?->format('Y-m-d H:i:s'),
                'clock_out' => $clockOut?->format('Y-m-d H:i:s'),
                'worked_minutes' => $calc['worked_minutes'],
                'status' => $calc['status'],
                'source' => AttendanceRecord::SOURCE_CSV_IMPORT,
            ],
        ];
    }
}
