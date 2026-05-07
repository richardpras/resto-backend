<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\AccountingPeriod;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

class AccountingPeriodService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    public function listForActor(?User $actor): Collection
    {
        $query = AccountingPeriod::query()->orderByDesc('start_date')->orderByDesc('id');
        if ($actor !== null) {
            $allowed = $this->outletAccessResolver->allowedOutletIds($actor);
            $query->where(function ($q) use ($allowed): void {
                $q->whereNull('outlet_id')->orWhereIn('outlet_id', $allowed === [] ? [-1] : $allowed);
            });
        }

        return $query->get();
    }

    /** @param array{name?:string,start_date:string,end_date:string,outlet_id?:int|null,tenant_id?:int|null} $data */
    public function create(array $data, ?User $actor): AccountingPeriod
    {
        if ($actor !== null && array_key_exists('outlet_id', $data) && $data['outlet_id'] !== null) {
            $this->assertOutletAllowedForActor($actor, (int) $data['outlet_id']);
        }
        $start = Carbon::parse($data['start_date'])->toDateString();
        $end = Carbon::parse($data['end_date'])->toDateString();
        if ($start > $end) {
            throw ValidationException::withMessages(['endDate' => ['The endDate must be a date after or equal to startDate.']]);
        }

        $this->assertNoOverlap(
            $start,
            $end,
            isset($data['tenant_id']) ? (int) $data['tenant_id'] : null,
            isset($data['outlet_id']) ? (int) $data['outlet_id'] : null
        );

        return AccountingPeriod::query()->create([
            'tenant_id' => $data['tenant_id'] ?? null,
            'outlet_id' => $data['outlet_id'] ?? null,
            'name' => $data['name'] ?? ('Period '.$start.' to '.$end),
            'start_date' => $start,
            'end_date' => $end,
            'status' => 'open',
        ]);
    }

    public function close(AccountingPeriod $period, ?User $actor): AccountingPeriod
    {
        if ($period->status === 'closed') {
            return $period;
        }

        if ($actor !== null && $period->outlet_id !== null) {
            $this->assertOutletAllowedForActor($actor, (int) $period->outlet_id);
        }

        return DB::transaction(function () use ($period, $actor): AccountingPeriod {
            $locked = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($locked->status === 'closed') {
                return $locked;
            }
            $locked->status = 'closed';
            $locked->closed_at = now();
            $locked->closed_by_user_id = $actor?->id;
            $locked->save();

            return $locked->refresh();
        });
    }

    public function open(AccountingPeriod $period, ?User $actor): AccountingPeriod
    {
        if ($period->status === 'open') {
            return $period;
        }
        if ($actor !== null && $period->outlet_id !== null) {
            $this->assertOutletAllowedForActor($actor, (int) $period->outlet_id);
        }

        $start = Carbon::parse($period->start_date)->toDateString();
        $end = Carbon::parse($period->end_date)->toDateString();
        $this->assertNoOverlap($start, $end, $period->tenant_id, $period->outlet_id, (int) $period->id);

        return DB::transaction(function () use ($period): AccountingPeriod {
            $locked = AccountingPeriod::query()->lockForUpdate()->findOrFail($period->id);
            if ($locked->status === 'open') {
                return $locked;
            }
            $locked->status = 'open';
            $locked->save();

            return $locked->refresh();
        });
    }

    public function assertDateOpen(string $journalDate, ?int $tenantId = null, ?int $outletId = null): void
    {
        $date = Carbon::parse($journalDate)->toDateString();
        $locked = AccountingPeriod::query()
            ->where('status', 'closed')
            ->whereDate('start_date', '<=', $date)
            ->whereDate('end_date', '>=', $date)
            ->when($tenantId !== null && $tenantId > 0, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($outletId !== null && $outletId > 0, fn ($q) => $q->where(function ($x) use ($outletId) {
                $x->whereNull('outlet_id')->orWhere('outlet_id', $outletId);
            }))
            ->exists();

        if ($locked) {
            throw new UnprocessableEntityHttpException('Journal date belongs to a closed accounting period.');
        }
    }

    private function assertOutletAllowedForActor(User $actor, int $outletId): void
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($actor);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outletId is invalid.'],
            ]);
        }
    }

    private function assertNoOverlap(string $start, string $end, ?int $tenantId, ?int $outletId, ?int $ignoreId = null): void
    {
        $exists = AccountingPeriod::query()
            ->when($ignoreId !== null, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->when($tenantId !== null && $tenantId > 0, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($outletId !== null && $outletId > 0, fn ($q) => $q->where(function ($x) use ($outletId) {
                $x->whereNull('outlet_id')->orWhere('outlet_id', $outletId);
            }), fn ($q) => $q->whereNull('outlet_id'))
            ->whereDate('start_date', '<=', $end)
            ->whereDate('end_date', '>=', $start)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'dateRange' => ['Accounting period overlaps an existing period.'],
            ]);
        }
    }
}
