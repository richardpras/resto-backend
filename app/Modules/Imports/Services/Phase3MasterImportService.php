<?php

namespace App\Modules\Imports\Services;

use App\Models\Modules\Imports\Domain\MasterImportBatch;
use App\Models\Modules\Loyalty\Domain\LoyaltyAccount;
use App\Models\Modules\Loyalty\Domain\LoyaltyPointsLedger;
use App\Models\Modules\UserManagement\Domain\Department;
use App\Models\Modules\UserManagement\Domain\Employee;
use App\Models\Modules\UserManagement\Domain\Position;
use App\Models\User;
use App\Modules\Imports\Support\CsvTableParser;
use App\Modules\Imports\Support\ImportSheetExtractor;
use App\Modules\Imports\Support\ImportTemplateSchema;
use App\Modules\Loyalty\Services\LoyaltyPointService;
use App\Modules\Settings\Support\OutletAccessResolver;
use App\Modules\UserManagement\Services\DepartmentService;
use App\Modules\UserManagement\Services\OrganizationEmployeeService;
use App\Modules\UserManagement\Services\PositionService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Phase3MasterImportService
{
    /** @var list<string> */
    public const IMPORT_ORDER = [
        'departments',
        'positions',
        'employees',
        'opening_loyalty_points',
    ];

    /** @var array<string, string> */
    private const FILE_MAP = [
        'departments' => '13_departments.csv',
        'positions' => '14_positions.csv',
        'employees' => '15_employees.csv',
        'opening_loyalty_points' => '16_opening_loyalty_points.csv',
    ];

    public function __construct(
        private readonly DepartmentService $departmentService,
        private readonly PositionService $positionService,
        private readonly OrganizationEmployeeService $organizationEmployeeService,
        private readonly LoyaltyPointService $loyaltyPointService,
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
        abort_if(! $file instanceof UploadedFile, 422, 'ZIP file is required.');
        $this->assertOutletAllowed($user, $outletId);

        $sheets = ImportSheetExtractor::extract($file);
        $context = $this->buildContext($outletId, $tenantId);
        $sections = [];

        foreach (self::IMPORT_ORDER as $type) {
            $filename = self::FILE_MAP[$type];
            $content = $sheets[$filename] ?? '';
            $sections[$type] = $this->processSection($type, $content, $context, $user, $preview);
        }

        return $this->finalizeResult('phase3_bundle', $sections, $preview, $user, $outletId, $tenantId, $file->getClientOriginalName());
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
            'phase3_'.$type,
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
        $departments = Department::query()
            ->where(function ($query) use ($outletId): void {
                $query->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            })
            ->get()
            ->keyBy(fn (Department $row) => strtolower((string) $row->code));

        $positions = Position::query()
            ->where(function ($query) use ($outletId): void {
                $query->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            })
            ->get()
            ->keyBy(fn (Position $row) => strtolower((string) $row->code));

        $employees = Employee::query()
            ->where('outlet_id', $outletId)
            ->get()
            ->keyBy(fn (Employee $row) => strtolower((string) $row->employee_no));

        $customers = LoyaltyAccount::query()
            ->where('outlet_id', $outletId)
            ->whereNull('merged_into_account_id')
            ->whereNotNull('import_code')
            ->get()
            ->keyBy(fn (LoyaltyAccount $row) => strtolower((string) $row->import_code));

        return [
            'outletId' => $outletId,
            'tenantId' => $tenantId,
            'departmentByCode' => $departments->all(),
            'positionByCode' => $positions->all(),
            'employeeByNo' => $employees->all(),
            'customerByCode' => $customers->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}
     */
    private function processSection(string $type, string $csv, array &$context, User $user, bool $preview): array
    {
        $rows = CsvTableParser::parse(
            $csv,
            ImportTemplateSchema::columnSpecsForFilename('phase3', self::FILE_MAP[$type] ?? ''),
        );
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'previewRows' => []];

        $execute = function () use ($type, $rows, &$context, $user, $preview, &$result): void {
            switch ($type) {
                case 'departments':
                    $this->importDepartments($rows, $context, $user, $preview, $result);
                    break;
                case 'positions':
                    $this->importPositions($rows, $context, $user, $preview, $result);
                    break;
                case 'employees':
                    $this->importEmployees($rows, $context, $user, $preview, $result);
                    break;
                case 'opening_loyalty_points':
                    $this->importOpeningLoyaltyPoints($rows, $context, $user, $preview, $result);
                    break;
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
    private function importDepartments(array $rows, array &$context, User $user, bool $preview, array &$result): void
    {
        foreach ($rows as $entry) {
            $row = $entry['row'];
            $data = $entry['data'];
            $code = trim($data['code'] ?? '');
            $name = trim($data['name'] ?? '');
            $codeKey = strtolower($code);

            if ($code === '' || $name === '') {
                $result['errors'][] = ['row' => $row, 'message' => 'code and name are required.'];

                continue;
            }

            $payload = [
                'outletId' => $context['outletId'],
                'code' => $code,
                'name' => $name,
                'description' => trim($data['description'] ?? '') ?: null,
                'isActive' => $this->toBool($data['active'] ?? '1'),
            ];

            $existing = $context['departmentByCode'][$codeKey] ?? null;
            if ($existing instanceof Department) {
                $result['previewRows'][] = ['code' => $code, 'action' => 'update'];
                if ($preview) {
                    $result['updated']++;

                    continue;
                }
                $this->departmentService->update($user, $existing, $payload);
                $result['updated']++;

                continue;
            }

            $result['previewRows'][] = ['code' => $code, 'action' => 'create'];
            if ($preview) {
                $stub = new Department(['code' => $code, 'name' => $name]);
                $stub->id = -$row;
                $context['departmentByCode'][$codeKey] = $stub;
                $result['created']++;

                continue;
            }

            $department = $this->departmentService->create($user, $payload);
            $context['departmentByCode'][$codeKey] = $department;
            $result['created']++;
        }
    }

    /**
     * @param  list<array{row:int,data:array<string,string>}>  $rows
     * @param  array<string, mixed>  $context
     * @param  array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}  $result
     */
    private function importPositions(array $rows, array &$context, User $user, bool $preview, array &$result): void
    {
        foreach ($rows as $entry) {
            $row = $entry['row'];
            $data = $entry['data'];
            $code = trim($data['code'] ?? '');
            $name = trim($data['name'] ?? '');
            $codeKey = strtolower($code);

            if ($code === '' || $name === '') {
                $result['errors'][] = ['row' => $row, 'message' => 'code and name are required.'];

                continue;
            }

            $departmentCode = strtolower(trim($data['department_code'] ?? ''));
            $departmentId = null;
            if ($departmentCode !== '') {
                $department = $context['departmentByCode'][$departmentCode] ?? null;
                if (! $department instanceof Department) {
                    $result['errors'][] = ['row' => $row, 'message' => "Department code [{$data['department_code']}] not found."];

                    continue;
                }
                $departmentId = (int) $department->id;
            }

            $payload = [
                'outletId' => $context['outletId'],
                'departmentId' => $departmentId,
                'code' => $code,
                'name' => $name,
                'description' => trim($data['description'] ?? '') ?: null,
                'sortOrder' => (int) ($data['sort_order'] ?? 0),
                'isActive' => $this->toBool($data['active'] ?? '1'),
            ];

            $existing = $context['positionByCode'][$codeKey] ?? null;
            if ($existing instanceof Position) {
                $result['previewRows'][] = ['code' => $code, 'action' => 'update'];
                if ($preview) {
                    $result['updated']++;

                    continue;
                }
                $this->positionService->update($user, $existing, $payload);
                $result['updated']++;

                continue;
            }

            $result['previewRows'][] = ['code' => $code, 'action' => 'create'];
            if ($preview) {
                $stub = new Position(['code' => $code, 'name' => $name]);
                $stub->id = -$row;
                $context['positionByCode'][$codeKey] = $stub;
                $result['created']++;

                continue;
            }

            $position = $this->positionService->create($user, $payload);
            $context['positionByCode'][$codeKey] = $position;
            $result['created']++;
        }
    }

    /**
     * @param  list<array{row:int,data:array<string,string>}>  $rows
     * @param  array<string, mixed>  $context
     * @param  array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}  $result
     */
    private function importEmployees(array $rows, array &$context, User $user, bool $preview, array &$result): void
    {
        foreach ($rows as $entry) {
            $row = $entry['row'];
            $data = $entry['data'];
            $employeeNo = trim($data['employee_no'] ?? '');
            $fullName = trim($data['full_name'] ?? '');
            $employeeKey = strtolower($employeeNo);

            if ($employeeNo === '' || $fullName === '') {
                $result['errors'][] = ['row' => $row, 'message' => 'employee_no and full_name are required.'];

                continue;
            }

            $status = strtolower(trim($data['status'] ?? 'active'));
            if (! in_array($status, ['active', 'inactive', 'resigned', 'terminated'], true)) {
                $result['errors'][] = ['row' => $row, 'message' => 'Invalid status value.'];

                continue;
            }

            $positionId = null;
            $departmentId = null;
            $positionCode = strtolower(trim($data['position_code'] ?? ''));
            $departmentCode = strtolower(trim($data['department_code'] ?? ''));

            if ($positionCode !== '') {
                $position = $context['positionByCode'][$positionCode] ?? null;
                if (! $position instanceof Position) {
                    $result['errors'][] = ['row' => $row, 'message' => "Position code [{$data['position_code']}] not found."];

                    continue;
                }
                $positionId = (int) $position->id;
            }
            if ($departmentCode !== '') {
                $department = $context['departmentByCode'][$departmentCode] ?? null;
                if (! $department instanceof Department) {
                    $result['errors'][] = ['row' => $row, 'message' => "Department code [{$data['department_code']}] not found."];

                    continue;
                }
                $departmentId = (int) $department->id;
            }

            $payload = [
                'outletId' => $context['outletId'],
                'employeeNo' => $employeeNo,
                'fullName' => $fullName,
                'email' => trim($data['email'] ?? '') ?: null,
                'phone' => trim($data['phone'] ?? '') ?: null,
                'gender' => trim($data['gender'] ?? '') ?: null,
                'birthDate' => trim($data['birth_date'] ?? '') ?: null,
                'hireDate' => trim($data['hire_date'] ?? '') ?: null,
                'status' => $status,
                'positionId' => $positionId,
                'departmentId' => $departmentId,
                'baseSalary' => $this->toFloat($data['base_salary'] ?? '0'),
                'notes' => trim($data['notes'] ?? '') ?: null,
            ];

            $existing = $context['employeeByNo'][$employeeKey] ?? null;
            if ($existing instanceof Employee) {
                $result['previewRows'][] = ['employeeNo' => $employeeNo, 'action' => 'update'];
                if ($preview) {
                    $result['updated']++;

                    continue;
                }
                $updated = $this->organizationEmployeeService->update($user, $existing, $payload);
                $this->applyEmployeePayrollFields($updated, $data);
                $result['updated']++;

                continue;
            }

            $result['previewRows'][] = ['employeeNo' => $employeeNo, 'action' => 'create'];
            if ($preview) {
                $stub = new Employee(['employee_no' => $employeeNo, 'full_name' => $fullName]);
                $stub->id = -$row;
                $context['employeeByNo'][$employeeKey] = $stub;
                $result['created']++;

                continue;
            }

            $employee = $this->organizationEmployeeService->create($user, $payload);
            $this->applyEmployeePayrollFields($employee, $data);
            $context['employeeByNo'][$employeeKey] = $employee->fresh() ?? $employee;
            $result['created']++;
        }
    }

    /**
     * @param  list<array{row:int,data:array<string,string>}>  $rows
     * @param  array<string, mixed>  $context
     * @param  array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}  $result
     */
    private function importOpeningLoyaltyPoints(array $rows, array $context, User $user, bool $preview, array &$result): void
    {
        $outletId = (int) $context['outletId'];

        foreach ($rows as $entry) {
            $row = $entry['row'];
            $data = $entry['data'];
            $customerCode = strtolower(trim($data['customer_code'] ?? ''));
            $points = (int) $this->toFloat($data['points'] ?? '0');

            if ($customerCode === '') {
                $result['errors'][] = ['row' => $row, 'message' => 'customer_code is required.'];

                continue;
            }
            if ($points < 1) {
                $result['skipped']++;

                continue;
            }

            $customer = $context['customerByCode'][$customerCode] ?? null;
            if (! $customer instanceof LoyaltyAccount) {
                $result['errors'][] = ['row' => $row, 'message' => "Customer code [{$data['customer_code']}] not found."];

                continue;
            }

            $idempotencyKey = "master_import_opening_pts:{$outletId}:{$data['customer_code']}";
            if (! $preview && $this->hasOpeningPointsLedger($outletId, $idempotencyKey)) {
                $result['skipped']++;

                continue;
            }

            $result['previewRows'][] = ['customerCode' => $data['customer_code'], 'points' => $points];
            if ($preview) {
                $result['created']++;

                continue;
            }

            $accrue = $this->loyaltyPointService->accrue($user, $customer, [
                'outletId' => $outletId,
                'idempotencyKey' => $idempotencyKey,
                'pointsDelta' => $points,
                'meta' => [
                    'source' => 'master_import_phase3',
                    'memo' => trim($data['memo'] ?? '') ?: 'Opening loyalty points',
                ],
            ]);

            if ($accrue['idempotent'] ?? false) {
                $result['skipped']++;
            } else {
                $result['created']++;
            }
        }
    }

    /**
     * @param  array<string, string>  $data
     */
    private function applyEmployeePayrollFields(Employee $employee, array $data): void
    {
        $salaryType = strtolower(trim($data['salary_type'] ?? ''));
        if (in_array($salaryType, ['monthly', 'daily', 'hourly'], true)) {
            $employee->salary_type = $salaryType;
        }
        if (trim($data['overtime_rate'] ?? '') !== '') {
            $employee->overtime_rate = $this->toFloat($data['overtime_rate']);
        }
        if (trim($data['base_salary'] ?? '') !== '') {
            $employee->base_salary = $this->toFloat($data['base_salary']);
        }
        $employee->save();
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

    private function hasOpeningPointsLedger(int $outletId, string $idempotencyKey): bool
    {
        return LoyaltyPointsLedger::query()
            ->where('outlet_id', $outletId)
            ->where('idempotency_key', $idempotencyKey)
            ->exists();
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
