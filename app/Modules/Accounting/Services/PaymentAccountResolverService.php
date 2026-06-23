<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Settings\Domain\BankAccount;
use App\Models\Modules\Settings\Domain\OutletPaymentMethodConfig;
use App\Modules\Settings\Services\OutletPaymentMethodConfigService;
use App\Modules\Settings\Support\PaymentMethodCatalog;
use Illuminate\Validation\ValidationException;

final class PaymentAccountResolverService
{
    public function __construct(
        private readonly AccountingPostingIntegrityService $integrityService,
        private readonly OutletPaymentMethodConfigService $outletPaymentMethodConfigService,
    ) {}

    /**
     * @param  list<string>  $fallbackCodes
     * @param  list<string>  $types
     */
    public function resolveById(
        ?int $chartAccountId,
        string $category,
        array $fallbackCodes,
        array $types,
        ?int $outletId,
    ): Account {
        if ($chartAccountId !== null && $chartAccountId > 0) {
            $account = $this->findScopedAccountById($chartAccountId, $types, $outletId);
            if ($account !== null) {
                return $account;
            }
        }

        return $this->integrityService->resolveAccountOrFail($category, $fallbackCodes, $types, $outletId);
    }

    public function resolveForOutletPaymentMethod(int $outletId, string $settlementMethod): Account
    {
        $normalized = strtolower(trim($settlementMethod));
        if ($outletId > 0) {
            $this->outletPaymentMethodConfigService->ensureDefaultsForOutlet($outletId);

            $configs = OutletPaymentMethodConfig::query()
                ->where('outlet_id', $outletId)
                ->where('enabled', true)
                ->orderBy('display_order')
                ->orderBy('id')
                ->get();

            foreach ($configs as $config) {
                $configSettlement = PaymentMethodCatalog::settlementMethodForType((string) $config->type);
                if ($configSettlement !== $normalized) {
                    continue;
                }

                if ($config->chart_account_id !== null && (int) $config->chart_account_id > 0) {
                    $account = $this->findScopedAccountById((int) $config->chart_account_id, ['asset'], $outletId);
                    if ($account !== null) {
                        return $account;
                    }
                }
            }
        }

        return match ($normalized) {
            'cash' => $this->integrityService->resolveAccountOrFail('cash_bank', ['1100'], ['asset'], $outletId > 0 ? $outletId : null),
            'qris', 'ewallet' => $this->integrityService->resolveAccountOrFail('cash_bank', ['1120', '1100'], ['asset'], $outletId > 0 ? $outletId : null),
            'transfer', 'card' => $this->integrityService->resolveAccountOrFail('bank', ['1111', '1110', '1100'], ['asset'], $outletId > 0 ? $outletId : null),
            default => $this->integrityService->resolveAccountOrFail('cash_bank', ['1100'], ['asset'], $outletId > 0 ? $outletId : null),
        };
    }

    public function resolveForBankAccount(?string $bankAccountId, ?int $outletId): Account
    {
        if ($bankAccountId !== null && $bankAccountId !== '') {
            $bank = BankAccount::query()->whereKey($bankAccountId)->first();
            if ($bank !== null && $bank->chart_account_id !== null && (int) $bank->chart_account_id > 0) {
                $account = $this->findScopedAccountById((int) $bank->chart_account_id, ['asset'], $outletId);
                if ($account !== null) {
                    return $account;
                }
            }
        }

        return $this->integrityService->resolveAccountOrFail('bank', ['1111', '1110', '1100'], ['asset'], $outletId);
    }

    public function resolveForCash(?int $outletId): Account
    {
        if ($outletId !== null && $outletId > 0) {
            return $this->resolveForOutletPaymentMethod($outletId, 'cash');
        }

        return $this->integrityService->resolveAccountOrFail('cash_bank', ['1100'], ['asset'], $outletId);
    }

    /**
     * @param  list<string>  $types
     */
    private function findScopedAccountById(int $accountId, array $types, ?int $outletId): ?Account
    {
        $query = Account::query()
            ->whereKey($accountId)
            ->whereIn('type', $types)
            ->where('is_active', true);

        if ($outletId !== null && $outletId > 0) {
            $query->where(function ($q) use ($outletId): void {
                $q->whereNull('outlet_id')->orWhere('outlet_id', $outletId);
            });
        }

        $account = $query->first();
        if ($account === null) {
            return null;
        }

        return $account;
    }
}
