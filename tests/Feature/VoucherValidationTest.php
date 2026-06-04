<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use App\Models\Modules\Settings\Domain\Outlet;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\OrderVoucherTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class VoucherValidationTest extends TestCase
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

    public function test_minimum_spend_validation(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet, [
            'minimum_spend' => 300000,
        ]);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['memberVoucherId']);
    }

    public function test_expired_member_voucher_rejected(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);

        app(\App\Modules\LoyaltyEngine\Services\MemberVoucherService::class)->updateStatus(
            $user,
            $memberVoucher->fresh() ?? $memberVoucher,
            MemberVoucher::STATUS_EXPIRED,
        );

        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['memberVoucherId']);
    }

    public function test_redeemed_member_voucher_rejected(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher, MemberVoucher::STATUS_CLAIMED);

        app(\App\Modules\LoyaltyEngine\Services\MemberVoucherService::class)->updateStatus(
            $user,
            $memberVoucher->fresh() ?? $memberVoucher,
            MemberVoucher::STATUS_REDEEMED,
        );

        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['memberVoucherId']);
    }

    public function test_outlet_isolation(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $otherOutlet = $this->createOrderVoucherOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $otherMember = Member::query()->create([
            'outlet_id' => $otherOutlet->id,
            'member_no' => 'M-OTHER',
            'full_name' => 'Other',
            'name' => 'Other',
            'phone' => '081299999999',
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);
        $otherVoucher = $this->createOrderVoucherDefinition($otherOutlet, ['code' => 'OTHER']);
        $otherMemberVoucher = $this->issueOrderVoucherMemberVoucher($user, $otherMember, $otherVoucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $otherMemberVoucher->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['memberVoucherId']);
    }

    public function test_member_ownership_validation(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $otherMember = Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'M-OTHER2',
            'full_name' => 'Other Member',
            'name' => 'Other Member',
            'phone' => '081288888888',
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);
        $voucher = $this->createOrderVoucherDefinition($outlet);
        $otherMemberVoucher = $this->issueOrderVoucherMemberVoucher($user, $otherMember, $voucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $otherMemberVoucher->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['memberVoucherId']);
    }

    public function test_free_item_voucher_rejected(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet, [
            'value_type' => LoyaltyVoucher::VALUE_FREE_ITEM,
            'value' => 0,
        ]);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['memberVoucherId']);
    }

    public function test_inactive_voucher_definition_rejected(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet, ['is_active' => false]);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['memberVoucherId']);
    }

    public function test_voucher_validity_window_enforced(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $voucher->update([
            'valid_from' => Carbon::parse('2026-07-01'),
            'valid_until' => Carbon::parse('2026-07-31'),
        ]);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->freezeVoucherTime(Carbon::parse('2026-06-15'));

        try {
            $this->postJson("/api/v1/orders/{$orderId}/voucher", [
                'memberVoucherId' => $memberVoucher->id,
            ])
                ->assertStatus(422)
                ->assertJsonValidationErrors(['voucherId']);
        } finally {
            $this->clearFrozenTime();
        }
    }
}
