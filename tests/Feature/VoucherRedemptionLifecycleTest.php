<?php

namespace Tests\Feature;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use App\Models\Modules\Orders\Domain\OrderVoucher;
use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Modules\LoyaltyEngine\Services\VoucherOrderLifecycleService;
use App\Modules\Orders\Events\OrderLifecycleChanged;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\OrderVoucherTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class VoucherRedemptionLifecycleTest extends TestCase
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

    public function test_issued_voucher_redeemed_when_order_fully_paid(): void
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
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'paid');

        $this->assertDatabaseHas('member_vouchers', [
            'id' => $memberVoucher->id,
            'status' => MemberVoucher::STATUS_REDEEMED,
        ]);
        self::assertNotNull(MemberVoucher::query()->find($memberVoucher->id)?->redeemed_at);
    }

    public function test_claimed_voucher_redeemed_when_order_fully_paid(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher(
            $user,
            $member,
            $voucher,
            MemberVoucher::STATUS_CLAIMED,
        );
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 275000]],
        ])->assertOk();

        $this->assertDatabaseHas('member_vouchers', [
            'id' => $memberVoucher->id,
            'status' => MemberVoucher::STATUS_REDEEMED,
        ]);
    }

    public function test_partial_to_paid_triggers_redemption(): void
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
            'payments' => [['method' => 'cash', 'amount' => 100000]],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'partial');

        $this->assertDatabaseHas('member_vouchers', [
            'id' => $memberVoucher->id,
            'status' => MemberVoucher::STATUS_ISSUED,
        ]);

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 175000]],
        ])->assertOk()->assertJsonPath('data.paymentStatus', 'paid');

        $this->assertDatabaseHas('member_vouchers', [
            'id' => $memberVoucher->id,
            'status' => MemberVoucher::STATUS_REDEEMED,
        ]);
    }

    public function test_cancelled_order_before_paid_does_not_redeem_voucher(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])->assertOk();

        $this->patchJson("/api/v1/orders/{$orderId}/status", [
            'status' => 'cancelled',
        ])->assertOk();

        app(VoucherOrderLifecycleService::class)->handleOrderLifecycleChanged(
            new OrderLifecycleChanged(
                outletId: (int) $outlet->id,
                orderId: $orderId,
                status: 'cancelled',
                paymentStatus: 'unpaid',
                kitchenStatus: 'queued',
            ),
        );

        $this->assertDatabaseHas('member_vouchers', [
            'id' => $memberVoucher->id,
            'status' => MemberVoucher::STATUS_ISSUED,
        ]);
        self::assertNull(MemberVoucher::query()->find($memberVoucher->id)?->redeemed_at);
    }

    public function test_redeemed_voucher_is_not_re_redeemed_on_lifecycle_event(): void
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

        $firstRedeemedAt = MemberVoucher::query()->find($memberVoucher->id)?->redeemed_at;
        self::assertNotNull($firstRedeemedAt);

        app(VoucherOrderLifecycleService::class)->handleOrderLifecycleChanged(
            new OrderLifecycleChanged(
                outletId: (int) $outlet->id,
                orderId: $orderId,
                status: 'completed',
                paymentStatus: 'paid',
                kitchenStatus: 'queued',
            ),
        );

        $secondRedeemedAt = MemberVoucher::query()->find($memberVoucher->id)?->redeemed_at;
        self::assertEquals(
            $firstRedeemedAt?->toIso8601String(),
            $secondRedeemedAt?->toIso8601String(),
        );
    }

    public function test_redemption_writes_audit_event(): void
    {
        [$user, $outlet] = $this->actAsPosUserWithOutlet();
        $member = $this->createOrderVoucherMember($outlet);
        $voucher = $this->createOrderVoucherDefinition($outlet, ['code' => 'AUDIT']);
        $memberVoucher = $this->issueOrderVoucherMemberVoucher($user, $member, $voucher);
        $orderId = $this->createOrderWithMember($outlet, $member);

        $this->postJson("/api/v1/orders/{$orderId}/voucher", [
            'memberVoucherId' => $memberVoucher->id,
        ])->assertOk();

        $this->postJson("/api/v1/orders/{$orderId}/payments", [
            'payments' => [['method' => 'cash', 'amount' => 275000]],
        ])->assertOk();

        $this->assertDatabaseHas('pos_event_logs', [
            'event_type' => 'voucher.redeemed',
            'entity_type' => 'member_voucher',
            'entity_id' => $memberVoucher->id,
        ]);

        $log = PosEventLog::query()
            ->where('event_type', 'voucher.redeemed')
            ->where('entity_id', $memberVoucher->id)
            ->first();

        self::assertSame($orderId, (int) ($log?->payload['orderId'] ?? 0));
        self::assertSame((int) $member->id, (int) ($log?->payload['memberId'] ?? 0));
    }
}
