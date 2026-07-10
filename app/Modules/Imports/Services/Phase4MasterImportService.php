<?php

namespace App\Modules\Imports\Services;

use App\Models\Modules\HR\Domain\EmployeeSalaryProfile;
use App\Models\Modules\Imports\Domain\MasterImportBatch;
use App\Models\Modules\UserManagement\Domain\Employee;
use App\Models\User;
use App\Modules\HR\Services\EmployeeSalaryProfileService;
use App\Modules\Imports\Support\CsvTableParser;
use App\Modules\Imports\Support\ImportSheetExtractor;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Phase4MasterImportService
{
    /** @var list<string> */
    public const IMPORT_ORDER = [
        'employee_salary_profiles',
    ];

    /** @var array<string, string> */
    private const FILE_MAP = [
        'employee_salary_profiles' => '17_employee_salary_profiles.csv',
    ];

    public function __construct(
        private readonly EmployeeSalaryProfileService $salaryProfileService,
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function importBundle(User $user, array $payload): array
    {
        $outletId = (int) ($payload['outletId'] ?? 0);
        $tenantId = isset($payload['tenantId']) ? (int) $payload['tenantId'] : null;
        $preview = (bool) ($payload['preview'] ?? false);
        $file = $payload['file'] ?? null;

        abort_if($outletId < 1, 422, 'outletId is required.');
        abort_if(! $file instanceof UploadedFile, 422, 'ZIP or XLSX file is required.');
        $this->assertOutletAllowed($user, $outletId);

        $sheets = ImportSheetExtractor::extract($file);
        $context = $this->buildContext($outletId, $tenantId);
        $sections = [];

        foreach (self::IMPORT_ORDER as $type) {
            $filename = self::FILE_MAP[$type];
            $content = $sheets[$filename] ?? '';
            $sections[$type] = $this->processSection($type, $content, $context, $user, $preview);
        }

        return $this->finalizeResult('phase4_bundle', $sections, $preview, $user, $outletId, $tenantId, $file->getClientOriginalName());
    }

    /**
     * @return array<string, mixed>
     */
    public function importType(User $user, string $type, array $payload): array
    {
        abort_unless(in_array($type, self::IMPORT_ORDER, true), 404, 'Unknown import type.');

        $outletId = (int) ($payload['outletId'] ?? 0);
        $tenantId = isset($payload['tenantId']) ? (int) $payload['tenantId'] : null;
        $preview = (bool) ($payload['preview'] ?? false);
        $csv = (string) ($payload['csv'] ?? '');

        abort_if($outletId < 1, 422, 'outletId is required.');
        abort_if(trim($csv) === '', 422, 'CSV content is required.');
        $this->assertOutletAllowed($user, $outletId);

        $context = $this->buildContext($outletId, $tenantId);
        $section = $this->processSection($type, $csv, $context, $user, $preview);

        return $this->finalizeResult(
            'phase4_'.$type,
            [$type => $section],
            $preview,
            $user,
            $outletId,
            $tenantId,
            (string) ($payload['filename'] ?? self::FILE_MAP[$type]),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildContext(int $outletId, ?int $tenantId): array
    {
        $employees = Employee::query()
            ->where('outlet_id', $outletId)
            ->get()
            ->keyBy(fn (Employee $row) => strtolower((string) $row->employee_no));

        $profiles = EmployeeSalaryProfile::query()
            ->whereHas('employee', fn ($query) => $query->where('outlet_id', $outletId))
            ->get()
            ->keyBy(fn (EmployeeSalaryProfile $row) => (int) $row->employee_id);

        return [
            'outletId' => $outletId,
            'tenantId' => $tenantId,
            'employeeByNo' => $employees->all(),
            'profileByEmployeeId' => $profiles->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}
     */
    private function processSection(string $type, string $csv, array &$context, User $user, bool $preview): array
    {
        $rows = CsvTableParser::parse($csv);
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'previewRows' => []];

        $execute = function () use ($type, $rows, &$context, $user, $preview, &$result): void {
            if ($type === 'employee_salary_profiles') {
                $this->importEmployeeSalaryProfiles($rows, $context, $user, $preview, $result);
            }
        };

        if (! $preview) {
            DB::transaction($execute);
        } else {
            $execute();
        }

        return $result;
    }

    /**
     * @param  list<array{row:int,data:array<string,string>}>  $rows
     * @param  array<string, mixed>  $context
     * @param  array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}  $result
     */
    private function importEmployeeSalaryProfiles(array $rows, array &$context, User $user, bool $preview, array &$result): void
    {
        foreach ($rows as $entry) {
            $row = $entry['row'];
            $data = $entry['data'];
            $employeeNo = trim($data['employee_no'] ?? '');
            $employeeKey = strtolower($employeeNo);

            if ($employeeNo === '') {
                $result['errors'][] = ['row' => $row, 'message' => 'employee_no is required.'];

                continue;
            }

            if (trim($data['basic_salary'] ?? '') === '') {
                $result['errors'][] = ['row' => $row, 'message' => 'basic_salary is required.'];

                continue;
            }

            $employee = $context['employeeByNo'][$employeeKey] ?? null;
            if (! $employee instanceof Employee) {
                $result['errors'][] = ['row' => $row, 'message' => "Employee no [{$employeeNo}] not found."];

                continue;
            }

            $overtimeRateType = strtolower(trim($data['overtime_rate_type'] ?? ''));
            if ($overtimeRateType === '') {
                $overtimeRateType = EmployeeSalaryProfile::OVERTIME_RATE_FIXED_HOURLY;
            }
            if (! in_array($overtimeRateType, [
                EmployeeSalaryProfile::OVERTIME_RATE_FIXED_HOURLY,
                EmployeeSalaryProfile::OVERTIME_RATE_MULTIPLIER_HOURLY,
            ], true)) {
                $result['errors'][] = ['row' => $row, 'message' => 'Invalid overtime_rate_type.'];

                continue;
            }

            $attendanceEnabled = $this->toBool($data['attendance_deduction_enabled'] ?? '0');
            $attendancePerDay = trim($data['attendance_deduction_per_day'] ?? '');
            if ($attendanceEnabled && ($attendancePerDay === '' || $this->toFloat($attendancePerDay) <= 0)) {
                $result['errors'][] = ['row' => $row, 'message' => 'attendance_deduction_per_day is required when attendance deduction is enabled.'];

                continue;
            }

            $payload = [
                'employeeId' => (int) $employee->id,
                'basicSalary' => $this->toFloat($data['basic_salary']),
                'defaultAllowance' => $this->toFloat($data['default_allowance'] ?? '0'),
                'defaultDeduction' => $this->toFloat($data['default_deduction'] ?? '0'),
                'overtimeRateType' => $overtimeRateType,
                'overtimeRateValue' => $this->toFloat($data['overtime_rate_value'] ?? '0'),
                'unpaidLeaveDeductionEnabled' => $this->toBool($data['unpaid_leave_deduction_enabled'] ?? '1'),
                'attendanceDeductionEnabled' => $attendanceEnabled,
                'attendanceDeductionPerDay' => $attendanceEnabled
                    ? $this->toFloat($attendancePerDay)
                    : null,
            ];

            $existing = $context['profileByEmployeeId'][(int) $employee->id] ?? null;
            if ($existing instanceof EmployeeSalaryProfile) {
                $result['previewRows'][] = ['employeeNo' => $employeeNo, 'action' => 'update'];
                if ($preview) {
                    $result['updated']++;

                    continue;
                }

                $updated = $this->salaryProfileService->update($user, (int) $existing->id, $payload);
                $context['profileByEmployeeId'][(int) $employee->id] = $updated;
                $result['updated']++;

                continue;
            }

            $result['previewRows'][] = ['employeeNo' => $employeeNo, 'action' => 'create'];
            if ($preview) {
                $stub = new EmployeeSalaryProfile(['employee_id' => $employee->id]);
                $stub->id = -$row;
                $context['profileByEmployeeId'][(int) $employee->id] = $stub;
                $result['created']++;

                continue;
            }

            $profile = $this->salaryProfileService->create($user, $payload);
            $context['profileByEmployeeId'][(int) $employee->id] = $profile;
            $result['created']++;
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    private function finalizeResult(
        string $importType,
        array $sections,
        bool $preview,
        User $user,
        int $outletId,
        ?int $tenantId,
        string $filename,
    ): array {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errorCount = 0;

        foreach ($sections as $section) {
            $created += (int) ($section['created'] ?? 0);
            $updated += (int) ($section['updated'] ?? 0);
            $skipped += (int) ($section['skipped'] ?? 0);
            $errorCount += count($section['errors'] ?? []);
        }

        $batch = null;
        if (! $preview) {
            $batch = MasterImportBatch::query()->create([
                'outlet_id' => $outletId,
                'tenant_id' => $tenantId,
                'import_type' => $importType,
                'filename' => $filename,
                'created_count' => $created,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
                'error_count' => $errorCount,
                'summary_json' => ['sections' => $sections],
                'created_by_user_id' => $user->id,
            ]);
        }

        return [
            'preview' => $preview,
            'canCommit' => $errorCount === 0,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errorCount' => $errorCount,
            'sections' => $sections,
            'batchId' => $batch?->id,
        ];
    }

    private function assertOutletAllowed(User $user, int $outletId): void
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outletId is invalid.'],
            ]);
        }
    }

    private function toFloat(string $value): float
    {
        return (float) str_replace(',', '.', trim($value));
    }

    private function toBool(string $value): bool
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, ['1', 'true', 'yes', 'y', 'active'], true);
    }
}
