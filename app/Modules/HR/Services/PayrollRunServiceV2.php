<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\HR\Domain\PayrollPreparationPeriod;
use App\Models\Modules\HR\Domain\PayrollRunItemV2;
use App\Models\Modules\HR\Domain\PayrollRunV2;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PayrollRunServiceV2
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly PayrollCalculationService $calculation,
    ) {}

    /**
     * @return Collection<int, PayrollRunV2>
     */
    public function list(?User $user, array $filters = []): Collection
    {
        $query = PayrollRunV2::query()
            ->with('preparationPeriod')
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

    public function findAccessible(?User $user, int $runId): PayrollRunV2
    {
        $run = PayrollRunV2::query()
            ->with(['preparationPeriod', 'items.employee'])
            ->find($runId);

        abort_if($run === null, Response::HTTP_NOT_FOUND, 'Payroll run not found.');
        $this->assertOutletAllowed($user, (int) $run->outlet_id);

        return $run;
    }

    public function create(?User $user, array $payload): PayrollRunV2
    {
        $periodId = (int) ($payload['payrollPreparationPeriodId'] ?? 0);
        abort_if($periodId < 1, 422, 'payrollPreparationPeriodId is required.');

        $period = PayrollPreparationPeriod::query()->find($periodId);
        abort_if($period === null, Response::HTTP_NOT_FOUND, 'Payroll preparation period not found.');

        $this->assertOutletAllowed($user, (int) $period->outlet_id);

        if ($period->status !== PayrollPreparationPeriod::STATUS_LOCKED) {
            throw ValidationException::withMessages([
                'payrollPreparationPeriodId' => ['Only locked payroll preparation periods can be used for payroll runs.'],
            ]);
        }

        $exists = PayrollRunV2::query()
            ->where('payroll_preparation_period_id', $periodId)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'payrollPreparationPeriodId' => ['A payroll run already exists for this preparation period.'],
            ]);
        }

        return PayrollRunV2::query()->create([
            'outlet_id' => $period->outlet_id,
            'payroll_preparation_period_id' => $periodId,
            'status' => PayrollRunV2::STATUS_DRAFT,
        ]);
    }

    public function calculate(?User $user, int $runId): PayrollRunV2
    {
        $run = $this->findAccessible($user, $runId);

        if (! in_array($run->status, [PayrollRunV2::STATUS_DRAFT, PayrollRunV2::STATUS_CALCULATED], true)) {
            throw ValidationException::withMessages([
                'status' => ['Only draft or calculated runs can be recalculated.'],
            ]);
        }

        $run->load('preparationPeriod');

        DB::transaction(function () use ($run) {
            $this->calculation->calculateRun($run);
            $run->update(['status' => PayrollRunV2::STATUS_CALCULATED]);
        });

        return $run->refresh()->load(['preparationPeriod', 'items.employee']);
    }

    public function approve(?User $user, int $runId): PayrollRunV2
    {
        $run = $this->findAccessible($user, $runId);

        if ($run->status !== PayrollRunV2::STATUS_CALCULATED) {
            throw ValidationException::withMessages([
                'status' => ['Only calculated runs can be approved.'],
            ]);
        }

        $run->update([
            'status' => PayrollRunV2::STATUS_APPROVED,
            'approved_by' => $user?->id,
            'approved_at' => now(),
        ]);

        return $run->refresh()->load(['preparationPeriod', 'items.employee']);
    }

    public function finalize(?User $user, int $runId): PayrollRunV2
    {
        $run = $this->findAccessible($user, $runId);

        if ($run->status !== PayrollRunV2::STATUS_APPROVED) {
            throw ValidationException::withMessages([
                'status' => ['Only approved runs can be finalized.'],
            ]);
        }

        $run->update([
            'status' => PayrollRunV2::STATUS_FINALIZED,
            'finalized_by' => $user?->id,
            'finalized_at' => now(),
        ]);

        return $run->refresh()->load(['preparationPeriod', 'items.employee']);
    }

    /**
     * @return Collection<int, PayrollRunItemV2>
     */
    public function items(?User $user, int $runId): Collection
    {
        $run = $this->findAccessible($user, $runId);

        return PayrollRunItemV2::query()
            ->with('employee')
            ->where('payroll_run_id', $run->id)
            ->orderBy('employee_id')
            ->get();
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
