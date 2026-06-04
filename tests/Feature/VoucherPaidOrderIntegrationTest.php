<?php

namespace Tests\Feature;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\OrderVoucherTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class VoucherPaidOrderIntegrationTest extends TestCase
{
    use OrderVoucherTestFixtures;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_paid_order_flow_redeems_voucher_and_updates_profile(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet, [
            'name' => 'Paid Flow Voucher',
            'value_type' => LoyaltyVoucher::VALUE_PERCENTAGE,
            'value' => 10,
        ]);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 275000]],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'paid');

        $this->getJson("/api/v1/members/{$member->id}/profile?outletId={$outlet->id}")
            ->assertOk()
            ->assertJsonPath('data.voucherHistory.0.status', MemberVoucher::STATUS_REDEEMED)
            ->assertJsonPath('data.voucherHistory.0.name', 'Paid Flow Voucher');

        self::assertNotNull($this->getJson("/api/v1/members/{$member->id}/profile?outletId={$outlet->id}")->json('data.voucherHistory.0.redeemedAt'));
    }

    public function test_analytics_reflect_redemption_after_paid_order(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet, [
            'code' => 'REDEEM1',
            'name' => 'Redeem Analytics',
            'value_type' => LoyaltyVoucher::VALUE_FIXED_AMOUNT,
            'value' => 50000,
        ]);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 275000]],
        ])->assertOk();

        $analytics = app(\App\Modules\LoyaltyEngine\Services\LoyaltyVoucherAnalyticsService::class)
            ->summary($user, (int) $outlet->id);

        self::assertSame(1, $analytics['voucherRedemptionCount']);
        self::assertSame(50000.0, $analytics['voucherRedemptionValue']);
        self::assertCount(1, $analytics['topRedeemedVouchers']);
        self::assertSame('REDEEM1', $analytics['topRedeemedVouchers'][0]['voucherCode']);
    }

    public function test_redemption_analytics_are_outlet_isolated(): void
    {
        [$user, $allowedOutlet] = $this->actAsPosUserWithOutlet();
        $forbiddenOutlet = $this->createOrderVoucherOutlet();
        $member = $this->createOrderVoucherMember($allowedOutlet);
        $voucher = $this->createOrderVoucherDefinition($allowedOutlet);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createOrderWithMember($allowedOutlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 275000]],
        ])->assertOk();

        $allowedAnalytics = app(\App\Modules\LoyaltyEngine\Services\LoyaltyVoucherAnalyticsService::class)
            ->summary($user, (int) $allowedOutlet->id);
        $forbiddenAnalytics = app(\App\Modules\LoyaltyEngine\Services\LoyaltyVoucherAnalyticsService::class)
            ->summary(null, (int) $forbiddenOutlet->id);

        self::assertSame(1, $allowedAnalytics['voucherRedemptionCount']);
        self::assertSame(0, $forbiddenAnalytics['voucherRedemptionCount']);
    }

    public function test_voucher_preview_unchanged_after_redemption(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])->assertOk();

        $previewBefore = $this->getJson("/api/v1/orders/{$orderId}/voucher-preview")
            ->assertOk()
            ->json('preview');

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 275000]],
        ])->assertOk();

        $previewAfter = $this->getJson("/api/v1/orders/{$orderId}/voucher-preview")
            ->assertOk()
            ->json('preview');

        self::assertSame($previewBefore['discount'], $previewAfter['discount']);
        self::assertSame($previewBefore['subtotalAfterDiscount'], $previewAfter['subtotalAfterDiscount']);
    }
}
