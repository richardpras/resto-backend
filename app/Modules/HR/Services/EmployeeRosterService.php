<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\HR\Domain\EmployeeRoster;
use App\Models\Modules\HR\Domain\EmployeeShiftAssignment;
use App\Models\Modules\HR\Domain\Shift;
use App\Models\User;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class EmployeeRosterService
{
    public function __construct(
        private readonly EmployeeMasterService $employeeMaster,
    ) {}

    /**
     * @return array{rows: Collection<int, EmployeeRoster>, draftCount: int, publishedCount: int}
     */
    public function list(?User $user, array $filters = []): array
    {
        $query = EmployeeRoster::query()
            ->with(['employee', 'shift', 'outlet'])
            ->orderBy('roster_date')
            ->orderBy('employee_id');

        $this->applyOutletScope($query, $user);

        if (! empty($filters['outletId'])) {
            $query->where('outlet_id', (int) $filters['outletId']);
        }

        if (! empty($filters['employeeId'])) {
            $query->where('employee_id', (int) $filters['employeeId']);
        }

        if (! empty($filters['departmentId'])) {
            $departmentId = (int) $filters['departmentId'];
            $query->whereHas('employee', fn (Builder $q) => $q->where('department_id', $departmentId));
        }

        if (! empty($filters['status'])) {
            $query->where('status', (string) $filters['status']);
        }

        if (! empty($filters['fromDate'])) {
            $query->where('roster_date', '>=', $filters['fromDate']);
        }

        if (! empty($filters['toDate'])) {
            $query->where('roster_date', '<=', $filters['toDate']);
        }

        $rows = $query->get();

        return [
            'rows' => $rows,
            'draftCount' => $rows->where('status', EmployeeRoster::STATUS_DRAFT)->count(),
            'publishedCount' => $rows->where('status', EmployeeRoster::STATUS_PUBLISHED)->count(),
        ];
    }

    public function findAccessible(?User $user, int $rosterId): EmployeeRoster
    {
        $roster = EmployeeRoster::query()
            ->with(['employee', 'shift', 'outlet'])
            ->find($rosterId);

        abort_if($roster === null, Response::HTTP_NOT_FOUND, 'Roster entry not found.');

        $this->assertRosterAccessible($user, $roster);

        return $roster;
    }

    public function create(?User $user, array $payload): EmployeeRoster
    {
        $employee = $this->employeeMaster->findAccessible($user, (int) $payload['employeeId']);
        $outletId = (int) $employee->outlet_id;
        abort_if($outletId < 1, 422, 'Employee must have an outlet before scheduling.');

        $rosterDate = Carbon::parse($payload['rosterDate'])->toDateString();
        $this->assertUniqueRosterDay((int) $employee->id, $rosterDate, null);

        if (! empty($payload['shiftId'])) {
            Shift::query()->findOrFail((int) $payload['shiftId']);
        }

        return EmployeeRoster::query()->create([
            'outlet_id' => $outletId,
            'employee_id' => (int) $employee->id,
            'shift_id' => $payload['shiftId'] ?? null,
            'roster_date' => $rosterDate,
            'status' => EmployeeRoster::STATUS_DRAFT,
            'notes' => $payload['notes'] ?? null,
        ])->load(['employee', 'shift', 'outlet']);
    }

    public function update(?User $user, int $rosterId, array $payload): EmployeeRoster
    {
        $roster = $this->findAccessible($user, $rosterId);

        if (isset($payload['employeeId'])) {
            $employee = $this->employeeMaster->findAccessible($user, (int) $payload['employeeId']);
            $roster->employee_id = (int) $employee->id;
            $roster->outlet_id = (int) $employee->outlet_id;
        }

        if (isset($payload['rosterDate'])) {
            $rosterDate = Carbon::parse($payload['rosterDate'])->toDateString();
            $this->assertUniqueRosterDay((int) $roster->employee_id, $rosterDate, $roster->id);
            $roster->roster_date = $rosterDate;
        }

        if (array_key_exists('shiftId', $payload)) {
            if ($payload['shiftId'] !== null) {
                Shift::query()->findOrFail((int) $payload['shiftId']);
            }
            $roster->shift_id = $payload['shiftId'];
        }

        if (array_key_exists('notes', $payload)) {
            $roster->notes = $payload['notes'];
        }

        $roster->save();

        return $roster->refresh()->load(['employee', 'shift', 'outlet']);
    }

    public function delete(?User $user, int $rosterId): void
    {
        $this->findAccessible($user, $rosterId)->delete();
    }

    /**
     * @return array{created: int, skipped: int, updated: int}
     */
    public function generateFromAssignments(?User $user, array $payload): array
    {
        $from = Carbon::parse($payload['fromDate'])->startOfDay();
        $to = Carbon::parse($payload['toDate'])->startOfDay();
        abort_if($to->lt($from), 422, 'toDate must be on or after fromDate.');

        $overwrite = (bool) ($payload['overwriteExisting'] ?? false);
        $employees = $this->resolveEmployeesForBulk($user, $payload);

        $created = 0;
        $skipped = 0;
        $updated = 0;

        foreach ($employees as $employee) {
            foreach (CarbonPeriod::create($from, $to) as $date) {
                $dateStr = $date->toDateString();
                $shiftId = $this->resolveShiftIdFromAssignment((int) $employee->id, $date);
                if ($shiftId === null) {
                    $skipped++;

                    continue;
                }

                $existing = EmployeeRoster::query()
                    ->where('employee_id', $employee->id)
                    ->where('roster_date', $dateStr)
                    ->first();

                if ($existing !== null) {
                    if ($overwrite) {
                        $existing->fill([
                            'shift_id' => $shiftId,
                            'outlet_id' => (int) $employee->outlet_id,
                        ])->save();
                        $updated++;
                    } else {
                        $skipped++;
                    }

                    continue;
                }

                EmployeeRoster::query()->create([
                    'outlet_id' => (int) $employee->outlet_id,
                    'employee_id' => (int) $employee->id,
                    'shift_id' => $shiftId,
                    'roster_date' => $dateStr,
                    'status' => EmployeeRoster::STATUS_DRAFT,
                ]);
                $created++;
            }
        }

        return compact('created', 'skipped', 'updated');
    }

    /**
     * @return array{copied: int, skipped: int}
     */
    public function copySchedule(?User $user, array $payload): array
    {
        $sourceFrom = Carbon::parse($payload['sourceFrom'])->startOfDay();
        $sourceTo = Carbon::parse($payload['sourceTo'])->startOfDay();
        $destFrom = Carbon::parse($payload['destFrom'])->startOfDay();
        $destTo = Carbon::parse($payload['destTo'])->startOfDay();

        $sourceDays = $sourceFrom->diffInDays($sourceTo);
        $destDays = $destFrom->diffInDays($destTo);
        abort_if($sourceDays !== $destDays, 422, 'Source and destination ranges must span the same number of days.');

        $dayOffset = (int) $sourceFrom->diffInDays($destFrom);

        $query = EmployeeRoster::query()->with('employee');
        $this->applyOutletScope($query, $user);

        if (! empty($payload['outletId'])) {
            $query->where('outlet_id', (int) $payload['outletId']);
        }

        if (! empty($payload['employeeId'])) {
            $query->where('employee_id', (int) $payload['employeeId']);
        }

        $sourceRows = $query
            ->whereBetween('roster_date', [$sourceFrom->toDateString(), $sourceTo->toDateString()])
            ->get();

        $copied = 0;
        $skipped = 0;

        foreach ($sourceRows as $source) {
            $destDate = Carbon::parse($source->roster_date)->addDays($dayOffset)->toDateString();

            if (EmployeeRoster::query()
                ->where('employee_id', $source->employee_id)
                ->where('roster_date', $destDate)
                ->exists()) {
                $skipped++;

                continue;
            }

            EmployeeRoster::query()->create([
                'outlet_id' => $source->outlet_id,
                'employee_id' => $source->employee_id,
                'shift_id' => $source->shift_id,
                'roster_date' => $destDate,
                'status' => EmployeeRoster::STATUS_DRAFT,
                'notes' => $source->notes,
            ]);
            $copied++;
        }

        return compact('copied', 'skipped');
    }

    /**
     * @return array{published: int}
     */
    public function publish(?User $user, array $payload): array
    {
        $query = EmployeeRoster::query()->where('status', EmployeeRoster::STATUS_DRAFT);
        $this->applyOutletScope($query, $user);

        if (! empty($payload['outletId'])) {
            $query->where('outlet_id', (int) $payload['outletId']);
        }

        if (! empty($payload['employeeId'])) {
            $query->where('employee_id', (int) $payload['employeeId']);
        }

        if (! empty($payload['fromDate'])) {
            $query->where('roster_date', '>=', $payload['fromDate']);
        }

        if (! empty($payload['toDate'])) {
            $query->where('roster_date', '<=', $payload['toDate']);
        }

        $now = now();
        $published = 0;

        $query->chunkById(200, function ($rows) use ($now, &$published): void {
            foreach ($rows as $row) {
                $row->fill([
                    'status' => EmployeeRoster::STATUS_PUBLISHED,
                    'published_at' => $now,
                ])->save();
                $published++;
            }
        });

        return ['published' => $published];
    }

    /**
     * @return array{weekStart: string, weekEnd: string, days: list<array<string, mixed>>}
     */
    public function employeeSchedule(?User $user, int $employeeId, ?string $weekStart = null): array
    {
        $employee = $this->employeeMaster->findOrFail($employeeId);
        try {
            $this->employeeMaster->assertEmployeeOutletAllowed($user, $employee);
        } catch (ValidationException) {
            abort(Response::HTTP_FORBIDDEN, 'You cannot access schedules for this outlet.');
        }

        $start = $weekStart !== null
            ? Carbon::parse($weekStart)->startOfWeek(Carbon::MONDAY)
            : now()->startOfWeek(Carbon::MONDAY);

        $end = $start->copy()->addDays(6);

        $rosters = EmployeeRoster::query()
            ->with('shift')
            ->where('employee_id', $employeeId)
            ->whereBetween('roster_date', [$start->toDateString(), $end->toDateString()])
            ->get()
            ->keyBy(fn (EmployeeRoster $r) => $r->roster_date->toDateString());

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date = $start->copy()->addDays($i);
            $key = $date->toDateString();
            $roster = $rosters->get($key);

            $days[] = [
                'date' => $key,
                'dayName' => $date->format('l'),
                'status' => $roster?->status,
                'publishedAt' => $roster?->published_at?->toIso8601String(),
                'shift' => $roster?->shift ? [
                    'id' => (int) $roster->shift->id,
                    'name' => $roster->shift->name,
                    'startTime' => $this->formatTime($roster->shift->start_time),
                    'endTime' => $this->formatTime($roster->shift->end_time),
                ] : null,
                'label' => $roster?->shift?->name ?? 'Off',
            ];
        }

        return [
            'weekStart' => $start->toDateString(),
            'weekEnd' => $end->toDateString(),
            'days' => $days,
        ];
    }

    /**
     * @return Collection<int, Employee>
     */
    private function resolveEmployeesForBulk(?User $user, array $payload): Collection
    {
        if (! empty($payload['employeeId'])) {
            return collect([$this->employeeMaster->findAccessible($user, (int) $payload['employeeId'])]);
        }

        $query = $this->employeeMaster->scopedEmployeeQuery($user)->where('status', Employee::STATUS_ACTIVE);

        if (! empty($payload['outletId'])) {
            $query->where('outlet_id', (int) $payload['outletId']);
        }

        if (! empty($payload['departmentId'])) {
            $query->where('department_id', (int) $payload['departmentId']);
        }

        return $query->get();
    }

    private function resolveShiftIdFromAssignment(int $employeeId, Carbon $date): ?int
    {
        $assignment = EmployeeShiftAssignment::query()
            ->where('employee_id', $employeeId)
            ->where('is_active', true)
            ->orderByDesc('effective_from')
            ->get()
            ->first(fn (EmployeeShiftAssignment $row) => $this->assignmentCoversDate($row, $date));

        return $assignment?->shift_id !== null ? (int) $assignment->shift_id : null;
    }

    private function assignmentCoversDate(EmployeeShiftAssignment $row, Carbon $date): bool
    {
        $from = $row->effective_from->copy()->startOfDay();
        $until = $row->effective_until?->copy()->startOfDay();

        if ($date->lt($from)) {
            return false;
        }

        if ($until !== null && $date->gt($until)) {
            return false;
        }

        return true;
    }

    private function assertUniqueRosterDay(int $employeeId, string $rosterDate, ?int $ignoreId): void
    {
        $exists = EmployeeRoster::query()
            ->where('employee_id', $employeeId)
            ->where('roster_date', $rosterDate)
            ->when($ignoreId !== null, fn (Builder $q) => $q->where('id', '!=', $ignoreId))
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'rosterDate' => ['This employee already has a roster entry for this date.'],
            ]);
        }
    }

    /**
     * @param  Builder<EmployeeRoster>  $query
     */
    private function applyOutletScope(Builder $query, ?User $user): void
    {
        $this->employeeMaster->scopeByEmployeeOutlet($query, $user, 'employee_id');
    }

    private function assertRosterAccessible(?User $user, EmployeeRoster $roster): void
    {
        if ($user === null) {
            return;
        }

        $roster->loadMissing('employee');

        try {
            $this->employeeMaster->assertEmployeeOutletAllowed($user, $roster->employee);
        } catch (ValidationException) {
            abort(Response::HTTP_FORBIDDEN, 'You cannot access schedules for this outlet.');
        }
    }

    private function formatTime(mixed $value): string
    {
        $str = (string) $value;

        return strlen($str) >= 5 ? substr($str, 0, 5) : $str;
    }
}
