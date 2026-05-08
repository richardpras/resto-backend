<?php

namespace Tests\Feature;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\GiftCards\Services\GiftCardSettlementHookService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class Phase15GiftCardStoreCreditTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();
        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_gift_card_double_spend_prevention(): void
    {
        [$outlet] = $this->actAsAdminWithOutlet();

        $issued = $this->postJson('/api/v1/gift-cards/issue', [
            'outletId' => (int) $outlet->id,
            'instrumentType' => 'gift_card',
            'code' => 'GC-DS-001',
            'initialAmount' => 100000,
            'currency' => 'IDR',
            'idempotencyKey' => 'issue-gc-ds-1',
        ])->assertCreated();

        $issuanceId = (int) $issued->json('data.issuance.id');

        $this->postJson('/api/v1/gift-cards/redeem', [
            'outletId' => (int) $outlet->id,
            'code' => 'GC-DS-001',
            'amount' => 80000,
            'idempotencyKey' => 'redeem-gc-ds-1',
        ])->assertCreated()->assertJsonPath('data.issuance.balanceAmount', 20000);

        $this->postJson('/api/v1/gift-cards/redeem', [
            'outletId' => (int) $outlet->id,
            'code' => 'GC-DS-001',
            'amount' => 30000,
            'idempotencyKey' => 'redeem-gc-ds-2',
        ])->assertStatus(422);

        $this->assertDatabaseHas('gift_card_issuances', [
            'id' => $issuanceId,
            'balance_amount' => 20000,
        ]);
    }

    public function test_idempotent_redeem_protection_and_settlement_hook_idempotency(): void
    {
        [$outlet] = $this->actAsAdminWithOutlet();

        $this->postJson('/api/v1/gift-cards/issue', [
            'outletId' => (int) $outlet->id,
            'instrumentType' => 'store_credit',
            'code' => 'SC-IDEMP-001',
            'initialAmount' => 90000,
            'currency' => 'IDR',
            'idempotencyKey' => 'issue-sc-idemp-1',
        ])->assertCreated();

        $response = $this->postJson('/api/v1/gift-cards/redeem', [
            'outletId' => (int) $outlet->id,
            'code' => 'SC-IDEMP-001',
            'amount' => 40000,
            'idempotencyKey' => 'redeem-sc-idemp-1',
            'referenceType' => 'order',
            'referenceId' => '101',
        ])->assertCreated();

        $firstSettlementId = (int) $response->json('data.settlement.id');
        $response->assertJsonPath('data.idempotent', false);

        $duplicate = $this->postJson('/api/v1/gift-cards/redeem', [
            'outletId' => (int) $outlet->id,
            'code' => 'SC-IDEMP-001',
            'amount' => 40000,
            'idempotencyKey' => 'redeem-sc-idemp-1',
            'referenceType' => 'order',
            'referenceId' => '101',
        ])->assertCreated();

        $duplicate->assertJsonPath('data.idempotent', true)
            ->assertJsonPath('data.settlement.id', $firstSettlementId);

        $service = app(GiftCardSettlementHookService::class);
        $first = $service->settle([
            'outletId' => (int) $outlet->id,
            'idempotencyKey' => 'settlement-hook-idemp-1',
            'settlementReference' => 'payment-1001',
            'paymentTransactionId' => null,
            'settlementStatus' => 'settled',
            'redeemSettlementIds' => [$firstSettlementId],
        ]);
        $second = $service->settle([
            'outletId' => (int) $outlet->id,
            'idempotencyKey' => 'settlement-hook-idemp-1',
            'settlementReference' => 'payment-1001',
            'paymentTransactionId' => null,
            'settlementStatus' => 'settled',
            'redeemSettlementIds' => [$firstSettlementId],
        ]);

        $this->assertFalse((bool) $first['idempotent']);
        $this->assertTrue((bool) $second['idempotent']);
    }

    public function test_outlet_isolation_for_gift_card_apis(): void
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $allowedOutlet = $this->createOutletFixture('GC-ALW');
        $blockedOutlet = $this->createOutletFixture('GC-BLK');
        $this->assignUserToOutlets($user, [(int) $allowedOutlet->id]);

        $this->postJson('/api/v1/gift-cards/issue', [
            'outletId' => (int) $blockedOutlet->id,
            'instrumentType' => 'gift_card',
            'code' => 'GC-ISO-001',
            'initialAmount' => 10000,
            'currency' => 'IDR',
            'idempotencyKey' => 'issue-gc-iso-1',
        ])->assertStatus(422)->assertJsonValidationErrors(['outletId']);
    }

    public function test_coupon_validation_endpoint_returns_expected_result(): void
    {
        [$outlet] = $this->actAsAdminWithOutlet();

        $valid = $this->postJson('/api/v1/promotions/coupons/validate', [
            'outletId' => (int) $outlet->id,
            'couponCode' => 'WELCOME10',
            'subtotal' => 60000,
        ])->assertOk();

        $valid->assertJsonPath('success', true)
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('data.discountType', 'percentage')
            ->assertJsonPath('data.discountValue', 10);

        $invalid = $this->postJson('/api/v1/promotions/coupons/validate', [
            'outletId' => (int) $outlet->id,
            'couponCode' => 'WELCOME10',
            'subtotal' => 20000,
        ])->assertOk();

        $invalid->assertJsonPath('data.valid', false)
            ->assertJsonPath('data.reasonCode', 'min_subtotal_not_met');
    }

    /** @return array{0:Outlet} */
    private function actAsAdminWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOutletFixture('GC');
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        return [$outlet];
    }

    private function createOutletFixture(string $prefix): Outlet
    {
        return Outlet::query()->create([
            'name' => $prefix.' Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => strtolower($prefix).'-'.uniqid(),
        ]);
    }
}
