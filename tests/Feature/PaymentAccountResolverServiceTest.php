<?php

namespace Tests\Feature;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Settings\Domain\BankAccount;
use App\Models\Modules\Settings\Domain\OutletPaymentMethodConfig;
use App\Modules\Accounting\Services\PaymentAccountResolverService;
use App\Modules\Settings\Support\PaymentMethodCatalog;
use Database\Seeders\EssentialCoaAccountsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaymentAccountResolverServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_essential_coa_seeder_creates_payroll_accounts(): void
    {
        $this->seed(EssentialCoaAccountsSeeder::class);

        $this->assertDatabaseHas('accounts', ['code' => '6100', 'category' => 'payroll_expense']);
        $this->assertDatabaseHas('accounts', ['code' => '1111', 'category' => 'bank']);
        $this->assertDatabaseHas('accounts', ['code' => '1120', 'category' => 'cash_bank']);
    }

    public function test_resolve_by_explicit_chart_account_id(): void
    {
        $this->seed(EssentialCoaAccountsSeeder::class);
        $bca = Account::query()->where('code', '1111')->firstOrFail();

        /** @var PaymentAccountResolverService $resolver */
        $resolver = app(PaymentAccountResolverService::class);
        $resolved = $resolver->resolveById((int) $bca->id, 'bank', ['1110'], ['asset'], null);

        $this->assertSame('1111', $resolved->code);
    }

    public function test_resolve_for_bank_account_uses_linked_chart_account(): void
    {
        $this->seed(EssentialCoaAccountsSeeder::class);
        $bca = Account::query()->where('code', '1111')->firstOrFail();

        BankAccount::query()->create([
            'id' => 'bank-test',
            'bank_name' => 'BCA',
            'account_name' => 'PT Test',
            'account_number' => '123',
            'is_default' => true,
            'chart_account_id' => (int) $bca->id,
        ]);

        /** @var PaymentAccountResolverService $resolver */
        $resolver = app(PaymentAccountResolverService::class);
        $resolved = $resolver->resolveForBankAccount('bank-test', null);

        $this->assertSame('1111', $resolved->code);
    }

    public function test_resolve_for_outlet_payment_method_uses_config_chart_account(): void
    {
        $this->seed(EssentialCoaAccountsSeeder::class);
        $qris = Account::query()->where('code', '1120')->firstOrFail();
        $outletId = (int) \App\Models\Modules\Settings\Domain\Outlet::query()->create([
            'code' => 'out-resolver',
            'name' => 'Resolver Outlet',
            'status' => 'active',
        ])->id;

        foreach (PaymentMethodCatalog::defaultRows() as $row) {
            OutletPaymentMethodConfig::query()->create([
                'outlet_id' => $outletId,
                'payment_method_code' => $row['paymentMethodCode'],
                'type' => $row['type'],
                'provider' => $row['provider'],
                'enabled' => $row['enabled'],
                'display_order' => $row['displayOrder'],
                'is_default' => $row['isDefault'],
                'settings' => $row['settings'],
                'chart_account_id' => $row['paymentMethodCode'] === 'manual_qris' ? (int) $qris->id : null,
            ]);
        }

        /** @var PaymentAccountResolverService $resolver */
        $resolver = app(PaymentAccountResolverService::class);
        $resolved = $resolver->resolveForOutletPaymentMethod($outletId, 'qris');

        $this->assertSame('1120', $resolved->code);
    }
}
