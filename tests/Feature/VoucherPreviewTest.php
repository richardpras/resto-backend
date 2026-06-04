<?php

namespace Tests\Feature;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\OrderVoucherTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class VoucherPreviewTest extends TestCase
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

    public function test_preview_without_voucher_returns_zero_discount(): void
    {
        [, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->getJson("/api/v1/orders/{$orderId}/voucher-preview")
            ->assertOk()
            ->assertJsonPath('preview.subtotal', 250000)
            ->assertJsonPath('preview.discount', 0)
            ->assertJsonPath('preview.subtotalAfterDiscount', 250000);
    }

    public function test_preview_reflects_applied_percentage_voucher(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet, [
            'value_type' => LoyaltyVoucher::VALUE_PERCENTAGE,
            'value' => 10,
        ]);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])->assertOk();

        $this->getJson("/api/v1/orders/{$orderId}/voucher-preview")
            ->assertOk()
            ->assertJsonPath('preview.discount', 25000)
            ->assertJsonPath('preview.subtotalAfterDiscount', 225000);
    }

    public function test_preview_recalculates_when_order_subtotal_changes(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet, [
            'value_type' => LoyaltyVoucher::VALUE_PERCENTAGE,
            'value' => 10,
        ]);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])->assertOk();

        $this->patchJson("/api/v1/orders/{$orderId}", [
            'items' => [[
                'id' => '202',
                'name' => 'Updated Item',
                'qty' => 1,
                'price' => 100000,
            ]],
            'subtotal' => 100000,
            'tax' => 10000,
            'total' => 110000,
        ])->assertOk();

        $this->getJson("/api/v1/orders/{$orderId}/voucher-preview")
            ->assertOk()
            ->assertJsonPath('preview.subtotal', 100000)
            ->assertJsonPath('preview.discount', 10000)
            ->assertJsonPath('preview.subtotalAfterDiscount', 90000);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'subtotal' => 100000,
            'total' => 110000,
        ]);
    }

    public function test_order_show_includes_voucher_preview_fields(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet, [
            'value_type' => LoyaltyVoucher::VALUE_FIXED_AMOUNT,
            'value' => 50000,
        ]);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher, MemberVoucher::STATUS_CLAIMED);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])->assertOk();

        $this->getJson("/api/v1/orders/{$orderId}")
            ->assertOk()
            ->assertJsonPath('data.voucherDiscount', 50000)
            ->assertJsonPath('data.voucherPreview.subtotalAfterDiscount', 200000)
            ->assertJsonPath('data.voucher.discountType', LoyaltyVoucher::VALUE_FIXED_AMOUNT);
    }

    public function test_analytics_tracks_voucher_applications_preview_amount(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet, [
            'code' => 'TOP1',
            'name' => 'Top Voucher',
            'value_type' => LoyaltyVoucher::VALUE_PERCENTAGE,
            'value' => 10,
        ]);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])->assertOk();

        $analytics = app(\App\Modules\LoyaltyEngine\Services\LoyaltyVoucherAnalyticsService::class)
            ->summary($user, (int) $outlet->id);

        self::assertSame(1, $analytics['voucherApplications']);
        self::assertSame(25000.0, $analytics['voucherPreviewAmount']);
        self::assertCount(1, $analytics['topVouchersUsed']);
        self::assertSame('TOP1', $analytics['topVouchersUsed'][0]['voucherCode']);
    }
}
