<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\AccountingSetting;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class AccountingSettingsService
{
    public function get(?int $tenantId = null, ?int $outletId = null): array
    {
        $row = $this->resolveRow($tenantId, $outletId);

        return [
            'revenuePostingMode' => $row?->revenue_posting_mode ?? AccountingSetting::MODE_REALTIME,
            'tenantId' => $tenantId,
            'outletId' => $outletId,
        ];
    }

    public function getRevenuePostingMode(?int $tenantId = null, ?int $outletId = null): string
    {
        $outletRow = $outletId !== null && $outletId > 0
            ? $this->findRow($tenantId, $outletId)
            : null;
        if ($outletRow !== null) {
            return (string) $outletRow->revenue_posting_mode;
        }

        $tenantRow = $this->findRow($tenantId, null);
        if ($tenantRow !== null) {
            return (string) $tenantRow->revenue_posting_mode;
        }

        return AccountingSetting::MODE_REALTIME;
    }

    public function isRealtimeMode(?int $tenantId = null, ?int $outletId = null): bool
    {
        return $this->getRevenuePostingMode($tenantId, $outletId) === AccountingSetting::MODE_REALTIME;
    }

    public function isShiftCloseMode(?int $tenantId = null, ?int $outletId = null): bool
    {
        return $this->getRevenuePostingMode($tenantId, $outletId) === AccountingSetting::MODE_SHIFT_CLOSE;
    }

    /** @param array<string,mixed> $data */
    public function update(?User $actor, ?int $tenantId, ?int $outletId, array $data): array
    {
        if (array_key_exists('revenuePostingMode', $data)) {
            $mode = (string) $data['revenuePostingMode'];
            $this->assertValidRevenueMode($mode);
        }

        $row = AccountingSetting::query()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'outlet_id' => $outletId,
            ],
            array_filter([
                'revenue_posting_mode' => isset($data['revenuePostingMode'])
                    ? (string) $data['revenuePostingMode']
                    : null,
            ], static fn ($v): bool => $v !== null)
        );

        if (isset($data['revenuePostingMode'])) {
            app(AccountingAuditService::class)->log(
                'revenue_source_changed',
                'accounting_settings',
                (int) $row->id,
                $outletId,
                $actor,
                ['revenuePostingMode' => (string) $data['revenuePostingMode']],
            );
        }

        return $this->get($tenantId, $outletId);
    }

    public function assertValidRevenueMode(string $mode): void
    {
        if (! in_array($mode, AccountingSetting::REVENUE_MODES, true)) {
            throw ValidationException::withMessages([
                'revenuePostingMode' => ['Revenue posting mode must be realtime or shift_close.'],
            ]);
        }
    }

    private function resolveRow(?int $tenantId, ?int $outletId): ?AccountingSetting
    {
        if ($outletId !== null && $outletId > 0) {
            $outletRow = $this->findRow($tenantId, $outletId);
            if ($outletRow !== null) {
                return $outletRow;
            }
        }

        return $this->findRow($tenantId, null);
    }

    private function findRow(?int $tenantId, ?int $outletId): ?AccountingSetting
    {
        if ($outletId !== null && $outletId > 0) {
            $byOutlet = AccountingSetting::query()->where('outlet_id', $outletId)->orderByDesc('id')->first();
            if ($byOutlet !== null) {
                return $byOutlet;
            }
        }

        return AccountingSetting::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'))
            ->when($outletId !== null && $outletId > 0, fn ($q) => $q->where('outlet_id', $outletId))
            ->when($outletId === null, fn ($q) => $q->whereNull('outlet_id'))
            ->first();
    }
}
