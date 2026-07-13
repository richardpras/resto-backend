<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\Loyalty\Domain\LoyaltyAccount;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletPaymentMethodConfig;
use App\Modules\Imports\Services\Phase2MasterImportService;
use App\Modules\Imports\Services\Phase2MasterImportTemplateService;
use App\Modules\Imports\Support\ImportTemplateSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\BugReportTestFixture;
use Tests\Concerns\BuildsMasterImportTestZip;
use Tests\TestCase;
use ZipArchive;

class Phase2MasterImportTest extends TestCase
{
    use BugReportTestFixture;
    use BuildsMasterImportTestZip;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_phase2_template_download(): void
    {
        $outlet = $this->createOutlet();
        $user = $this->createUserWithPermission('settings.manage', $outlet);
        Passport::actingAs($user);

        $this->get('/api/v1/imports/phase2/template')
            ->assertOk()
            ->assertHeader('content-disposition');
    }

    public function test_phase2_bundle_preview_and_commit(): void
    {
        $outlet = $this->createOutlet();
        $user = $this->createUserWithPermission('settings.manage', $outlet);
        $service = app(Phase2MasterImportService::class);

        $preview = $service->importBundle($user, [
            'outletId' => $outlet->id,
            'tenantId' => 1,
            'preview' => true,
            'file' => new UploadedFile($this->buildSampleZipOnDisk(), 'phase2.zip', 'application/zip', null, true),
        ]);
        $this->assertTrue($preview['canCommit']);
        $this->assertSame(2, $preview['sections']['chart_of_accounts']['created']);

        $commit = $service->importBundle($user, [
            'outletId' => $outlet->id,
            'tenantId' => 1,
            'preview' => false,
            'file' => new UploadedFile($this->buildSampleZipOnDisk(), 'phase2.zip', 'application/zip', null, true),
        ]);
        $this->assertSame(0, $commit['errorCount']);

        $this->assertDatabaseHas('accounts', ['code' => '1100', 'name' => 'Cash']);
        $this->assertDatabaseHas('accounts', ['code' => '3100', 'name' => "Owner's Equity"]);
        $this->assertTrue(
            Journal::query()->where('source_type', 'master_import_opening')->where('source_id', (string) $outlet->id)->exists()
        );
        $this->assertDatabaseHas('loyalty_accounts', [
            'outlet_id' => $outlet->id,
            'import_code' => 'CUST_001',
        ]);
        $this->assertDatabaseHas('members', [
            'outlet_id' => $outlet->id,
            'import_code' => 'MEM_001',
        ]);

        $cashConfig = OutletPaymentMethodConfig::query()
            ->where('outlet_id', $outlet->id)
            ->where('payment_method_code', 'cash')
            ->first();
        $this->assertNotNull($cashConfig);
        $this->assertSame(
            (int) Account::query()->where('code', '1100')->value('id'),
            (int) $cashConfig->chart_account_id,
        );
    }

    public function test_phase2_customers_csv_endpoint(): void
    {
        $outlet = $this->createOutlet();
        $user = $this->createUserWithPermission('members.manage', $outlet);
        Passport::actingAs($user);

        $csv = ImportTemplateSchema::findSheet('phase2', '10_customers.csv')
            ?->toCsvFromFieldExamples([[
                'code' => 'CUST_X', 'name' => 'Ani', 'phone' => '08111', 'email' => 'ani@example.com',
            ]]) ?? '';

        $this->postJson('/api/v1/imports/phase2/customers', [
            'outletId' => $outlet->id,
            'preview' => false,
            'csv' => $csv,
        ])->assertOk();

        $this->assertDatabaseHas('loyalty_accounts', [
            'outlet_id' => $outlet->id,
            'import_code' => 'CUST_X',
            'name' => 'Ani',
        ]);
    }

    private function createOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Phase2 Import Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'p2-'.uniqid('', true),
        ]);
    }

    private function buildSampleZipOnDisk(): string
    {
        return $this->buildPhaseZip('phase2', [
            '08_chart_of_accounts.csv' => [
                [
                    'code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'subtype' => 'current_asset',
                    'category' => 'cash_bank', 'parent_code' => '', 'description' => '', 'active' => '1',
                ],
                [
                    'code' => '3100', 'name' => "Owner's Equity", 'type' => 'equity', 'subtype' => 'equity',
                    'category' => 'equity', 'parent_code' => '', 'description' => '', 'active' => '1',
                ],
            ],
            '09_opening_balances.csv' => [
                ['account_code' => '1100', 'debit' => '1000000', 'credit' => '0', 'memo' => 'Cash', 'journal_date' => ''],
                ['account_code' => '3100', 'debit' => '0', 'credit' => '1000000', 'memo' => 'Equity', 'journal_date' => ''],
            ],
            '10_customers.csv' => [['code' => 'CUST_001', 'name' => 'Budi', 'phone' => '081234567890', 'email' => '']],
            '11_members.csv' => [[
                'code' => 'MEM_001', 'full_name' => 'Budi', 'phone' => '081234567890', 'email' => '',
                'birth_date' => '', 'gender' => '', 'status' => 'active', 'customer_code' => 'CUST_001', 'notes' => '',
            ]],
            '12_outlet_payment_methods.csv' => [[
                'payment_method_code' => 'cash', 'enabled' => '1', 'is_default' => '1', 'display_order' => '10',
                'provider' => '', 'chart_account_code' => '1100', 'instructions' => '',
            ]],
        ]);
    }
}
