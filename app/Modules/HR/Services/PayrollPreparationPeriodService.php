<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\AttendancePeriodLock;
use App\Models\Modules\HR\Domain\PayrollPreparationPeriod;
use App\Models\Modules\HR\Domain\PayrollPreparationSnapshot;
use App\Models\User;
use App\Modules\HR\Support\PeriodRangeOverlapGuard;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PayrollPreparationPeriodService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly PayrollPreparationService $preparation,
        private readonly AttendancePeriodService $attendancePeriods,
        private readonly PeriodRangeOverlapGuard $overlapGuard,
    ) {}

    /**
     * @return Collection<int, PayrollPreparationPeriod>
     */
    public function list(?User $user, array $filters = []): Collection
    {
        $query = PayrollPreparationPeriod::query()
            ->with('attendancePeriodLock')
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

    public function create(?User $user, array $payload): PayrollPreparationPeriod
    {
        $outletId = (int) ($payload['outletId'] ?? 0);
        $periodStart = (string) ($payload['periodStart'] ?? '');
        $periodEnd = (string) ($payload['periodEnd'] ?? '');

        abort_if($outletId < 1, 422, 'outletId is required.');
        $this->assertOutletAllowed($user, $outletId);

        if ($periodEnd < $periodStart) {
            throw ValidationException::withMessages([
                'periodEnd' => ['periodEnd must be on or after periodStart.'],
            ]);
        }

        $exists = PayrollPreparationPeriod::query()
            ->where('outlet_id', $outletId)
            ->where('period_start', $periodStart)
            ->where('period_end', $periodEnd)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'periodStart' => ['A payroll preparation period already exists for this outlet and date range.'],
            ]);
        }

        $this->overlapGuard->assertNoOverlapForOutlet($outletId, $periodStart, $periodEnd);

        return DB::transaction(function () use ($outletId, $periodStart, $periodEnd) {
            $period = PayrollPreparationPeriod::query()->create([
                'outlet_id' => $outletId,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => PayrollPreparationPeriod::STATUS_DRAFT,
            ]);

            AttendancePeriodLock::query()->create([
                'outlet_id' => $outletId,
                'payroll_preparation_period_id' => $period->id,
                'period_start' => $periodStart,
                'period_end' => $periodEnd,
                'status' => AttendancePeriodLock::STATUS_DRAFT,
            ]);

            return $period->load('attendancePeriodLock');
        });
    }

    public function findAccessible(?User $user, int $periodId): PayrollPreparationPeriod
    {
        $period = PayrollPreparationPeriod::query()
            ->with('attendancePeriodLock')
            ->find($periodId);
        abort_if($period === null, Response::HTTP_NOT_FOUND, 'Payroll preparation period not found.');
        $this->assertOutletAllowed($user, (int) $period->outlet_id);

        return $period;
    }

    public function approve(?User $user, int $periodId): PayrollPreparationPeriod
    {
        $period = $this->findAccessible($user, $periodId);

        if ($period->status !== PayrollPreparationPeriod::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only draft periods can be approved.'],
            ]);
        }

        if ($period->generated_at === null) {
            throw ValidationException::withMessages([
                'status' => ['Generate a snapshot before approving the period.'],
            ]);
        }

        $period->update([
            'status' => PayrollPreparationPeriod::STATUS_APPROVED,
            'approved_by' => $user?->id,
            'approved_at' => now(),
        ]);

        return $period->refresh()->load('attendancePeriodLock');
    }

    public function lock(?User $user, int $periodId): PayrollPreparationPeriod
    {
        $period = $this->findAccessible($user, $periodId);

        if ($period->status !== PayrollPreparationPeriod::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'status' => ['Only approved periods can be locked.'],
            ]);
        }

        $attendancePeriod = $period->attendancePeriodLock;
        if ($attendancePeriod === null) {
            throw ValidationException::withMessages([
                'status' => ['Linked attendance period is missing.'],
            ]);
        }

        if ($attendancePeriod->status === AttendancePeriodLock::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Approve the attendance period in Attendance Review before locking payroll preparation.'],
            ]);
        }

        return DB::transaction(function () use ($user, $period, $attendancePeriod) {
            $period->update([
                'status' => PayrollPreparationPeriod::STATUS_LOCKED,
                'locked_by' => $user?->id,
                'locked_at' => now(),
            ]);

            if ($attendancePeriod->status === AttendancePeriodLock::STATUS_APPROVED) {
                $this->attendancePeriods->lock($user, (int) $attendancePeriod->id);
            }

            return $period->refresh()->load('attendancePeriodLock');
        });
    }

    public function delete(?User $user, int $periodId): void
    {
        $period = $this->findAccessible($user, $periodId);

        if ($period->status !== PayrollPreparationPeriod::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Only draft payroll preparation periods can be deleted.'],
            ]);
        }

        if ($period->payrollRun()->exists()) {
            throw ValidationException::withMessages([
                'status' => ['This period is linked to a payroll run and cannot be deleted.'],
            ]);
        }

        DB::transaction(function () use ($period) {
            PayrollPreparationSnapshot::query()
                ->where('preparation_period_id', $period->id)
                ->delete();

            $period->delete();
        });
    }

    /**
     * @return Collection<int, PayrollPreparationSnapshot>
     */
    public function generate(?User $user, int $periodId): Collection
    {
        $period = $this->findAccessible($user, $periodId);

        if ($period->status === PayrollPreparationPeriod::STATUS_LOCKED) {
            throw ValidationException::withMessages([
                'status' => ['Locked payroll preparation periods cannot be regenerated.'],
            ]);
        }

        $attendancePeriod = $period->attendancePeriodLock;
        if ($attendancePeriod === null) {
            throw ValidationException::withMessages([
                'status' => ['Linked attendance period is missing.'],
            ]);
        }

        if (! in_array($attendancePeriod->status, [
            AttendancePeriodLock::STATUS_APPROVED,
            AttendancePeriodLock::STATUS_LOCKED,
        ], true)) {
            throw ValidationException::withMessages([
                'status' => ['Approve the attendance period in Attendance Review before generating snapshots.'],
            ]);
        }

        return $this->preparation->generateSnapshots($period);
    }

    /**
     * @return Collection<int, PayrollPreparationSnapshot>
     */
    public function snapshots(?User $user, int $periodId): Collection
    {
        $period = $this->findAccessible($user, $periodId);

        return PayrollPreparationSnapshot::query()
            ->with('employee')
            ->where('preparation_period_id', $period->id)
            ->orderBy('employee_id')
            ->get();
    }

    public function snapshotCount(PayrollPreparationPeriod $period): int
    {
        return (int) PayrollPreparationSnapshot::query()
            ->where('preparation_period_id', $period->id)
            ->count();
    }

    private function assertOutletAllowed(?User $user, int $outletId): void
    {
        if ($user === null) {
            return;
        }

        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if ($allowed !== [] && ! in_array($outletId, $allowed, true)) {
            abort(Response::HTTP_FORBIDDEN, 'Outlet access denied.');
        }
    }
}
