<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\AttendanceDailySummary;
use App\Models\Modules\HR\Domain\AttendancePeriodLock;
use App\Models\Modules\HR\Domain\PayrollPreparationPeriod;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AttendancePeriodService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @return Collection<int, AttendancePeriodLock>
     */
    public function list(?User $user, array $filters = []): Collection
    {
        $query = AttendancePeriodLock::query()
            ->orderByDesc('period_start')
            ->orderByDesc('id');

        if (! empty($filters['outletId'])) {
            $query->where('outlet_id', (int) $filters['outletId']);
        } elseif ($user !== null) {
            $allowed = $this->outletAccessResolver->allowedOutletIds($user);
            if ($allowed !== []) {
                $query->whereIn('outlet_id', $allowed);
            } else {
                $query->whereRaw('1 = 0');
            }
        }

        return $query->get();
    }

    public function create(?User $user, array $payload): AttendancePeriodLock
    {
        throw ValidationException::withMessages([
            'periodStart' => ['Create payroll preparation periods instead; attendance periods are created automatically.'],
        ]);
    }

    public function approve(?User $user, int $periodId): AttendancePeriodLock
    {
        $period = $this->findAccessible($user, $periodId);

        if ($period->status !== AttendancePeriodLock::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only draft periods can be approved.'],
            ]);
        }

        $period->update([
            'status' => AttendancePeriodLock::STATUS_APPROVED,
            'approved_by' => $user?->id,
            'approved_at' => now(),
        ]);

        return $period->refresh();
    }

    public function lock(?User $user, int $periodId): AttendancePeriodLock
    {
        $period = $this->findAccessible($user, $periodId);

        if ($period->status !== AttendancePeriodLock::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'status' => ['Only approved periods can be locked.'],
            ]);
        }

        $period->update([
            'status' => AttendancePeriodLock::STATUS_LOCKED,
            'locked_by' => $user?->id,
            'locked_at' => now(),
        ]);

        return $period->refresh();
    }

    public function reopen(?User $user, int $periodId): AttendancePeriodLock
    {
        $period = $this->findAccessible($user, $periodId);

        if ($period->status === AttendancePeriodLock::STATUS_LOCKED) {
            abort(Response::HTTP_FORBIDDEN, 'Locked attendance periods cannot be reopened.');
        }

        if ($period->status !== AttendancePeriodLock::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'status' => ['Only approved periods can be reopened.'],
            ]);
        }

        $period->loadMissing('payrollPreparationPeriod');
        $prep = $period->payrollPreparationPeriod;
        if ($prep !== null) {
            if ($prep->status === PayrollPreparationPeriod::STATUS_LOCKED) {
                abort(Response::HTTP_FORBIDDEN, 'Locked payroll preparation periods cannot be reopened from attendance review.');
            }

            if ($prep->status !== PayrollPreparationPeriod::STATUS_DRAFT || $prep->generated_at !== null) {
                $prep->update([
                    'status' => PayrollPreparationPeriod::STATUS_DRAFT,
                    'approved_by' => null,
                    'approved_at' => null,
                    'locked_by' => null,
                    'locked_at' => null,
                    'generated_at' => null,
                ]);
            }
        }

        $period->update([
            'status' => AttendancePeriodLock::STATUS_DRAFT,
            'approved_by' => null,
            'approved_at' => null,
            'locked_by' => null,
            'locked_at' => null,
        ]);

        return $period->refresh();
    }

    public function delete(?User $user, int $periodId): void
    {
        $period = $this->findAccessible($user, $periodId);

        if ($period->payroll_preparation_period_id !== null) {
            throw ValidationException::withMessages([
                'status' => ['Delete the payroll preparation period instead; attendance periods are managed from payroll preparation.'],
            ]);
        }

        if ($period->status !== AttendancePeriodLock::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only draft attendance periods can be deleted. Reopen approved periods first.'],
            ]);
        }

        $period->delete();
    }

    public function findExactPeriod(int $outletId, string $periodStart, string $periodEnd): ?AttendancePeriodLock
    {
        return AttendancePeriodLock::query()
            ->where('outlet_id', $outletId)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->first();
    }

    public function findCoveringPeriod(int $outletId, string $date): ?AttendancePeriodLock
    {
        return AttendancePeriodLock::query()
            ->where('outlet_id', $outletId)
            ->where('period_start', '<=', $date)
            ->where('period_end', '>=', $date)
            ->orderByDesc('id')
            ->first();
    }

    public function assertCanModifyAttendance(int $outletId, string $date): void
    {
        $period = $this->findCoveringPeriod($outletId, $date);

        if ($period !== null && $period->status === AttendancePeriodLock::STATUS_LOCKED) {
            abort(Response::HTTP_FORBIDDEN, 'Attendance is locked for this period. Modifications are not allowed.');
        }
    }

    public function assertCanReview(int $outletId, string $date): void
    {
        $period = $this->findCoveringPeriod($outletId, $date);

        if ($period === null) {
            return;
        }

        if ($period->status === AttendancePeriodLock::STATUS_LOCKED) {
            abort(Response::HTTP_FORBIDDEN, 'Attendance is locked for this period. Reviews are not allowed.');
        }

        if ($period->status === AttendancePeriodLock::STATUS_APPROVED) {
            abort(Response::HTTP_FORBIDDEN, 'Attendance period is approved. Reopen the period before adding reviews.');
        }
    }

    /**
     * @return array{lockStatus: ?string, approvedAt: ?string, lockedAt: ?string, periodId: ?int}
     */
    public function periodMetaForRange(int $outletId, string $periodStart, string $periodEnd): array
    {
        $period = $this->findExactPeriod($outletId, $periodStart, $periodEnd);

        if ($period === null) {
            return [
                'lockStatus' => null,
                'approvedAt' => null,
                'lockedAt' => null,
                'periodId' => null,
            ];
        }

        return [
            'lockStatus' => $period->status,
            'approvedAt' => $period->approved_at?->toIso8601String(),
            'lockedAt' => $period->locked_at?->toIso8601String(),
            'periodId' => (int) $period->id,
        ];
    }

    public function employeeCountForPeriod(AttendancePeriodLock $period): int
    {
        return (int) AttendanceDailySummary::query()
            ->where('outlet_id', $period->outlet_id)
            ->whereBetween('attendance_date', [
                $period->period_start->toDateString(),
                $period->period_end->toDateString(),
            ])
            ->distinct('employee_id')
            ->count('employee_id');
    }

    public function findAccessible(?User $user, int $periodId): AttendancePeriodLock
    {
        $period = AttendancePeriodLock::query()->find($periodId);
        abort_if($period === null, Response::HTTP_NOT_FOUND, 'Attendance period not found.');

        $this->assertOutletAllowed($user, (int) $period->outlet_id);

        return $period;
    }

    private function assertOutletAllowed(?User $user, int $outletId): void
    {
        if ($user === null) {
            return;
        }

        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            abort(Response::HTTP_FORBIDDEN, 'You cannot manage attendance periods for this outlet.');
        }
    }
}
