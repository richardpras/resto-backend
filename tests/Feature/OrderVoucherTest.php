<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use App\Models\Modules\Orders\Domain\OrderVoucher;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\OrderVoucherTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class OrderVoucherTest extends TestCase
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

    public function test_apply_percentage_voucher_reserves_on_order(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet, [
            'value_type' => LoyaltyVoucher::VALUE_PERCENTAGE,
            'value' => 10,
        ]);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $response = $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('preview.subtotal', 250000)
            ->assertJsonPath('preview.discount', 25000)
            ->assertJsonPath('preview.subtotalAfterDiscount', 225000)
            ->assertJsonPath('preview.tax', 22500)
            ->assertJsonPath('preview.total', 247500)
            ->assertJsonPath('data.voucher.voucherCode', $memberVoucher->voucher_code)
            ->assertJsonPath('data.voucherDiscount', 25000)
            ->assertJsonPath('data.tax', 22500)
            ->assertJsonPath('data.total', 247500);

        $this->assertDatabaseHas('order_vouchers', [
            'order_id' => $orderId,
            'member_voucher_id' => $memberVoucher->id,
            'discount_amount' => 25000,
        ]);

        $this->assertDatabaseHas('member_vouchers', [
            'id' => $memberVoucher->id,
            'status' => MemberVoucher::STATUS_ISSUED,
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'subtotal' => 250000,
            'discount_amount' => 25000,
            'tax' => 22500,
            'total' => 247500,
            'balance_due' => 247500,
        ]);
    }

    public function test_apply_fixed_amount_voucher(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet, [
            'value_type' => LoyaltyVoucher::VALUE_FIXED_AMOUNT,
            'value' => 50000,
        ]);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])
            ->assertOk()
            ->assertJsonPath('preview.discount', 50000)
            ->assertJsonPath('preview.subtotalAfterDiscount', 200000)
            ->assertJsonPath('preview.tax', 20000)
            ->assertJsonPath('preview.total', 220000);
    }

    public function test_remove_voucher(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])->assertOk();

        $this->deleteJson("/api/v1/orders/{$orderId}/voucher")
            ->assertOk()
            ->assertJsonPath('preview.discount', 0)
            ->assertJsonPath('preview.subtotalAfterDiscount', 250000)
            ->assertJsonPath('preview.tax', 25000)
            ->assertJsonPath('preview.total', 275000);

        $this->assertDatabaseMissing('order_vouchers', [
            'order_id' => $orderId,
        ]);
    }

    public function test_only_one_voucher_per_order(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $firstVoucher = $this->createOrderVoucherDefinition($outlet, ['code' => 'ONE']);
        $secondVoucher = $this->createOrderVoucherDefinition($outlet, ['code' => 'TWO']);
        $firstMemberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $firstVoucher);
        $secondMemberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $secondVoucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $firstMemberVoucher->id,
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $secondMemberVoucher->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['memberVoucherId']);

        self::assertSame(1, OrderVoucher::query()->where('order_id', $orderId)->count());
    }

    public function test_order_requires_member_before_voucher_apply(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);

        $response = $this->postJson('/api/v1/orders', $this->orderVoucherOrderPayload([
            'outletId' => $outlet->id,
        ]));
        $response->assertCreated();
        $orderId = (int) $response->json('data.id');

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['memberId']);
    }

    public function test_apply_voucher_by_code_auto_attaches_member(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet, [
            'value_type' => LoyaltyVoucher::VALUE_PERCENTAGE,
            'value' => 10,
        ]);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createWalkInOrder($outlet);

        $this->postJson("/api/v1/orders/{$orderId}/voucher/by-code", [
            'code' => $memberVoucher->voucher_code,
        ])
            ->assertOk()
            ->assertJsonPath('preview.discount', 25000)
            ->assertJsonPath('data.total', 247500);

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'member_id' => $member->id,
        ]);
    }

    public function test_apply_voucher_by_code_wrong_outlet(): void
    {
        [$user, $outletA] = $this->actAsPosUserWithOutlet();
        $outletB = $this->createOrderVoucherOutlet();
        $this->assignUserToOutlets($user, [(int) $outletA->id, (int) $outletB->id]);

        $member = $this->createOrderVoucherMember($outletA);
        $voucher = $this->createOrderVoucherDefinition($outletA);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createWalkInOrder($outletB);

        $this->postJson("/api/v1/orders/{$orderId}/voucher/by-code", [
            'code' => $memberVoucher->voucher_code,
        ])->assertUnprocessable();
    }
}
