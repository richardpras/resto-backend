<?php

namespace Tests\Concerns;

use App\Models\Member;
use App\Models\MemberTransaction;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Laravel\Passport\Passport;

trait LoyaltyAutomationTestFixtures
{
    protected function actingAsAutomationManager(): User
    {
        $this->seed(UserManagementPermissionsSeeder::class);
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_loyalty_automation_admin__'],
            ['description' => 'Members manage'],
        );
        $role->permissions()->sync(
            Permission::query()->where('code', 'members.manage')->pluck('id')->all(),
        );
        $user = User::factory()->create();
        $user->roles()->sync([$role->id]);
        Passport::actingAs($user);

        return $user;
    }

    protected function createAutomationOutlet(string $suffix = ''): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Automation Outlet '.$suffix.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lauto-'.$suffix.uniqid(),
        ]);
    }

    protected function createAutomationMember(int $outletId, string $label, ?string $birthDate = null): Member
    {
        return Member::query()->create([
            'outlet_id' => $outletId,
            'member_no' => 'M-'.uniqid(),
            'full_name' => $label,
            'name' => $label,
            'phone' => '08'.random_int(1000000000, 9999999999),
            'birth_date' => $birthDate,
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);
    }

    protected function createAutomationVoucher(int $outletId): LoyaltyVoucher
    {
        return LoyaltyVoucher::query()->create([
            'outlet_id' => $outletId,
            'code' => 'AUTO-'.uniqid(),
            'name' => 'Automation Voucher',
            'voucher_type' => LoyaltyVoucher::TYPE_MANUAL,
            'value_type' => LoyaltyVoucher::VALUE_FIXED_AMOUNT,
            'value' => 10000,
            'is_active' => true,
        ]);
    }

    protected function createAutomationViaApi(
        int $outletId,
        string $code,
        string $triggerType,
        string $actionType,
        array $condition = [],
        array $actionConfig = [],
    ): int {
        $response = $this->postJson('/api/v1/loyalty-automations', [
            'outletId' => $outletId,
            'code' => $code,
            'name' => $code.' Automation',
            'triggerType' => $triggerType,
            'condition' => $condition,
            'actionType' => $actionType,
            'actionConfig' => $actionConfig,
            'isActive' => true,
        ]);

        $response->assertCreated();

        return (int) $response->json('data.id');
    }

    protected function seedMemberVisit(Member $member, int $amount = 10000): void
    {
        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $member->outlet_id,
            'code' => 'AUTO-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Takeaway',
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => $amount,
            'tax' => 0,
            'total' => $amount,
            'member_id' => $member->id,
        ]);

        MemberTransaction::query()->create([
            'member_id' => $member->id,
            'order_id' => $order->id,
            'total_amount' => $amount,
            'transaction_at' => now(),
        ]);
    }

    protected function makeMemberInactive(Member $member, int $daysInactive): void
    {
        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $member->outlet_id,
            'code' => 'INACT-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Takeaway',
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => 10000,
            'tax' => 0,
            'total' => 10000,
            'member_id' => $member->id,
        ]);

        MemberTransaction::query()->create([
            'member_id' => $member->id,
            'order_id' => $order->id,
            'total_amount' => 10000,
            'transaction_at' => now()->subDays($daysInactive + 1),
        ]);
    }
}
