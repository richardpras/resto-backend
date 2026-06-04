<?php

namespace Tests\Feature;

use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use App\Modules\LoyaltyEngine\Services\VoucherOrderLifecycleService;
use App\Modules\Orders\Events\OrderLifecycleChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\OrderVoucherTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class VoucherRedemptionIdempotencyTest extends TestCase
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

    public function test_multiple_paid_lifecycle_events_redeem_once(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 275000]],
        ])->assertOk();

        $lifecycle = app(VoucherOrderLifecycleService::class);
        $event = new OrderLifecycleChanged(
            outletId: (int) $outlet->id,
            orderId: $orderId,
            status: 'completed',
            paymentStatus: 'paid',
            kitchenStatus: 'queued',
        );

        $lifecycle->handleOrderLifecycleChanged($event);
        $lifecycle->handleOrderLifecycleChanged($event);
        $lifecycle->handleOrderLifecycleChanged($event);

        self::assertSame(1, MemberVoucher::query()->where('status', MemberVoucher::STATUS_REDEEMED)->count());
    }

    public function test_already_redeemed_voucher_cannot_be_consumed_again(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);

        app(\App\Modules\LoyaltyEngine\Services\MemberVoucherService::class)->updateStatus(
            $user,
            $memberVoucher->fresh() ?? $memberVoucher,
            MemberVoucher::STATUS_CLAIMED,
        );
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

    public function test_unpaid_lifecycle_event_does_not_redeem(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])->assertOk();

        app(VoucherOrderLifecycleService::class)->handleOrderLifecycleChanged(
            new OrderLifecycleChanged(
                outletId: (int) $outlet->id,
                orderId: $orderId,
                status: 'confirmed',
                paymentStatus: 'unpaid',
                kitchenStatus: 'queued',
            ),
        );

        $this->assertDatabaseHas('member_vouchers', [
            'id' => $memberVoucher->id,
            'status' => MemberVoucher::STATUS_ISSUED,
        ]);
    }
}
