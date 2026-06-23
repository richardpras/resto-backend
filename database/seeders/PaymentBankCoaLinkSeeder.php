<?php

namespace Database\Seeders;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Settings\Domain\BankAccount;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletPaymentMethodConfig;
use App\Models\Modules\Settings\Domain\PaymentMethod;
use App\Modules\Settings\Services\OutletPaymentMethodConfigService;
use Illuminate\Database\Seeder;

/**
 * Links bank_accounts, payment_methods, and outlet payment configs to chart_account_id.
 * Idempotent — safe to re-run after COA or settings seed.
 */
class PaymentBankCoaLinkSeeder extends Seeder
{
    public function run(): void
    {
        $byCode = Account::query()->pluck('id', 'code');

        $this->linkBankAccounts($byCode);
        $this->linkPaymentMethods($byCode);
        $this->linkOutletPaymentConfigs($byCode);
    }

    /** @param \Illuminate\Support\Collection<string, int> $byCode */
    private function linkBankAccounts($byCode): void
    {
        $mapping = [
            'bank-default' => '1111',
        ];

        foreach ($mapping as $bankId => $accountCode) {
            $accountId = $byCode->get($accountCode);
            if ($accountId === null) {
                continue;
            }
            BankAccount::query()->whereKey($bankId)->update(['chart_account_id' => (int) $accountId]);
        }
    }

    /** @param \Illuminate\Support\Collection<string, int> $byCode */
    private function linkPaymentMethods($byCode): void
    {
        $mapping = [
            'pm-cash' => '1100',
            'pm-qris' => '1120',
            'pm-gopay' => '1120',
        ];

        foreach ($mapping as $methodId => $accountCode) {
            $accountId = $byCode->get($accountCode);
            if ($accountId === null) {
                continue;
            }
            PaymentMethod::query()->whereKey($methodId)->update(['chart_account_id' => (int) $accountId]);
        }
    }

    /** @param \Illuminate\Support\Collection<string, int> $byCode */
    private function linkOutletPaymentConfigs($byCode): void
    {
        $codeMapping = [
            'cash' => '1100',
            'manual_qris' => '1120',
            'gateway_qris' => '1120',
            'gateway_ewallet' => '1120',
            'manual_transfer' => '1111',
        ];

        /** @var OutletPaymentMethodConfigService $configService */
        $configService = app(OutletPaymentMethodConfigService::class);

        foreach (Outlet::query()->pluck('id') as $outletId) {
            $configService->ensureDefaultsForOutlet((int) $outletId);

            foreach ($codeMapping as $paymentMethodCode => $accountCode) {
                $accountId = $byCode->get($accountCode);
                if ($accountId === null) {
                    continue;
                }

                OutletPaymentMethodConfig::query()
                    ->where('outlet_id', (int) $outletId)
                    ->where('payment_method_code', $paymentMethodCode)
                    ->update(['chart_account_id' => (int) $accountId]);
            }
        }
    }
}
