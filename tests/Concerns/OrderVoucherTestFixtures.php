<?php

namespace Tests\Concerns;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Carbon\Carbon;

trait OrderVoucherTestFixtures
{
    /** @return array{0: User, 1: Outlet} */
    protected function actAsPosUserWithOutlet(): array
    {
        $user = $this->actingAsUserManagementApiAdministrator();
        $outlet = $this->createOrderVoucherOutlet();
        $this->assignUserToOutlets($user, [(int) $outlet->id]);

        return [$user, $outlet];
    }

    protected function createOrderVoucherOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Order Voucher Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'ov-'.uniqid(),
        ]);
    }

    protected function createOrderVoucherMember(Outlet $outlet): Member
    {
        return Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'M-'.uniqid(),
            'full_name' => 'Voucher Member',
            'name' => 'Voucher Member',
            'phone' => '0813'.random_int(10000000, 99999999),
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    protected function createOrderVoucherDefinition(Outlet $outlet, array $overrides = []): LoyaltyVoucher
    {
        return LoyaltyVoucher::query()->create(array_merge([
            'outlet_id' => $outlet->id,
            'code' => 'SAVE'.random_int(100, 999),
            'name' => 'Test Voucher',
            'voucher_type' => LoyaltyVoucher::TYPE_MANUAL,
            'value_type' => LoyaltyVoucher::VALUE_PERCENTAGE,
            'value' => 10,
            'minimum_spend' => 0,
            'is_active' => true,
        ], $overrides));
    }

    protected function issueOrderVoucherMemberVoucher(
        User $user,
        Member $member,
        LoyaltyVoucher $voucher,
        string $status = MemberVoucher::STATUS_ISSUED,
    ): MemberVoucher {
        $memberVoucher = app(\App\Modules\LoyaltyEngine\Services\MemberVoucherService::class)->issue(
            $user,
            $member,
            $voucher,
        );

        if ($status !== MemberVoucher::STATUS_ISSUED) {
            app(\App\Modules\LoyaltyEngine\Services\MemberVoucherService::class)->updateStatus(
                $user,
                $memberVoucher->fresh() ?? $memberVoucher,
                $status,
            );
        }

        return $memberVoucher->fresh() ?? $memberVoucher;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function orderVoucherOrderPayload(array $overrides = []): array
    {
        return array_merge([
            'tenantId' => 1,
            'code' => 'OV-'.uniqid(),
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'confirmed',
            'paymentStatus' => 'unpaid',
            'items' => [
                ['id' => '101', 'name' => 'Nasi Goreng', 'qty' => 1, 'price' => 250000],
            ],
            'subtotal' => 250000,
            'tax' => 25000,
            'total' => 275000,
            'payments' => [],
        ], $overrides);
    }

    protected function createOrderWithMember(Outlet $outlet, Member $member, array $overrides = []): int
    {
        $response = $this->postJson('/api/v1/orders', $this->orderVoucherOrderPayload(array_merge([
            'outletId' => $outlet->id,
            'memberId' => $member->id,
        ], $overrides)));

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    protected function freezeVoucherTime(Carbon $time): void
    {
        Carbon::setTestNow($time);
    }

    protected function clearFrozenTime(): void
    {
        Carbon::setTestNow();
    }
}
