<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\GiftCards\Services\GiftCardReconciliationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class GiftCardReconciliationTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seedAccounts();
    }

    public function test_reconciliation_balanced_when_issue_gl_posted(): void
    {
        [$outlet] = $this->actAsAdminWithOutlet();

        $this->postJson('/api/v1/gift-cards/issue', [
            'outletId' => (int) $outlet->id,
            'instrumentType' => 'gift_card',
            'code' => 'GC-RECON-BAL',
            'initialAmount' => 60000,
            'currency' => 'IDR',
            'idempotencyKey' => 'issue-gc-recon-bal',
            'cashReceivedAmount' => 60000,
            'paymentMethod' => 'cash',
        ])->assertCreated();

        $report = app(GiftCardReconciliationService::class)->report(null, (int) $outlet->id);

        $this->assertEquals(60000, $report['giftCardLiabilityOutstanding']);
        $this->assertEquals(60000, $report['giftCardLiabilityGLBalance']);
        $this->assertEquals(0, $report['giftCardLiabilityVariance']);
        $this->assertEquals('balanced', $report['status']);
    }

    public function test_reconciliation_shows_variance_when_issue_gl_missing(): void
    {
        [$outlet] = $this->actAsAdminWithOutlet();

        $this->postJson('/api/v1/gift-cards/issue', [
            'outletId' => (int) $outlet->id,
            'instrumentType' => 'gift_card',
            'code' => 'GC-RECON-VAR',
            'initialAmount' => 45000,
            'currency' => 'IDR',
            'idempotencyKey' => 'issue-gc-recon-var',
        ])->assertCreated();

        $report = app(GiftCardReconciliationService::class)->report(null, (int) $outlet->id);

        $this->assertEquals(45000, $report['giftCardLiabilityOutstanding']);
        $this->assertEquals(0, $report['giftCardLiabilityGLBalance']);
        $this->assertEquals(45000, $report['giftCardLiabilityVariance']);
        $this->assertEquals('review', $report['status']);
        $this->assertGreaterThan(0, $report['pendingGlIssuances']);
    }

    public function test_reconciliation_api_returns_extended_fields(): void
    {
        $this->actingAsUserManagementApiAdministrator();

        $this->getJson('/api/v1/accounting/reconciliation/gift-cards')
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'giftCardLiabilityOutstanding',
                    'giftCardLiabilityGLBalance',
                    'giftCardLiabilityVariance',
                    'storeCreditLiabilityOutstanding',
                    'storeCreditLiabilityGLBalance',
                    'storeCreditLiabilityVariance',
                    'status',
                    'pendingGlIssuances',
                ],
            ]);
    }

    /** @return array{0:Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = Outlet::query()->create([
            'name' => 'GC Recon Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'gc-recon-'.uniqid(),
        ]);
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        return [$outlet];
    }

    private function seedAccounts(): void
    {
        foreach ([
            ['code' => '1100', 'name' => 'Cash', 'type' => 'asset', 'category' => 'cash_bank'],
            ['code' => '2130', 'name' => 'Gift Card Liability', 'type' => 'liability', 'category' => 'gift_card_liability'],
            ['code' => '2135', 'name' => 'Store Credit Liability', 'type' => 'liability', 'category' => 'store_credit_liability'],
        ] as $row) {
            if (DB::table('accounts')->where('code', $row['code'])->exists()) {
                continue;
            }
            DB::table('accounts')->insert([
                'tenant_id' => 1,
                'code' => $row['code'],
                'name' => $row['name'],
                'type' => $row['type'],
                'category' => $row['category'],
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
