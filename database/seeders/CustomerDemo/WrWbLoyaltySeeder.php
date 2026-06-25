<?php

namespace Database\Seeders\CustomerDemo;

use App\Models\Member;
use App\Models\Modules\Loyalty\Domain\LoyaltyAccount;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgram;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyProgramRule;
use App\Models\Modules\Orders\Domain\Order;
use App\Modules\LoyaltyEngine\Services\LoyaltySpendEarningService;
use App\Modules\Orders\DTOs\CreateOrderData;
use App\Modules\Orders\Services\OrderService;
use Illuminate\Database\Seeder;

class WrWbLoyaltySeeder extends Seeder
{
    public function run(): void
    {
        $outletId = CustomerDemoContext::outletId();
        $cashier = CustomerDemoContext::user('kasir1');

        $program = LoyaltyProgram::query()->updateOrCreate(
            ['outlet_id' => $outletId, 'code' => 'WRWB-SPEND'],
            [
                'name' => 'WR WB Spend Points',
                'type' => LoyaltyProgram::TYPE_SPEND_BASED,
                'is_active' => true,
            ],
        );

        LoyaltyProgramRule::query()->updateOrCreate(
            ['loyalty_program_id' => $program->id, 'rule_type' => LoyaltyProgram::TYPE_SPEND_BASED],
            [
                'config' => [
                    'earnPerAmount' => 10000,
                    'pointsEarned' => 1,
                ],
            ],
        );

        $account = LoyaltyAccount::query()->updateOrCreate(
            ['outlet_id' => $outletId, 'phone' => '081255501234'],
            [
                'customer_uuid' => 'wrwb-demo-member-001',
                'global_customer_uuid' => 'wrwb-global-member-001',
                'name' => 'Member Demo WR WB',
                'email' => 'member@wrwb.demo',
                'points_balance' => 0,
                'lifetime_points_earned' => 0,
                'lifetime_spend' => 0,
                'lifetime_visits' => 0,
            ],
        );

        $member = Member::query()->updateOrCreate(
            ['outlet_id' => $outletId, 'phone' => '081255501234'],
            [
                'loyalty_account_id' => $account->id,
                'member_no' => 'WRWB-MEM-001',
                'full_name' => 'Member Demo WR WB',
                'name' => 'Member Demo WR WB',
                'email' => 'member@wrwb.demo',
                'points' => 0,
                'is_active' => true,
                'status' => 'active',
            ],
        );

        $code = 'WRWB-LOYALTY-01';
        if (Order::query()->where('code', $code)->exists()) {
            return;
        }

        $menu = \App\Models\Modules\Menu\Domain\MenuItem::query()
            ->where('outlet_id', $outletId)
            ->orderBy('id')
            ->first();

        if ($menu === null) {
            return;
        }

        $total = (float) $menu->price * 2;
        $when = CustomerDemoContext::date(29, 14);

        $order = app(OrderService::class)->create(CreateOrderData::fromArray([
            'tenantId' => CustomerDemoContext::TENANT_ID,
            'outletId' => $outletId,
            'code' => $code,
            'source' => 'pos',
            'orderType' => 'Takeaway',
            'status' => 'completed',
            'paymentStatus' => 'paid',
            'memberId' => $member->id,
            'items' => [[
                'id' => (string) $menu->id,
                'name' => $menu->name,
                'qty' => 2,
                'price' => (float) $menu->price,
            ]],
            'subtotal' => $total,
            'tax' => 0,
            'total' => $total,
            'payments' => [['method' => 'cash', 'amount' => $total]],
            'createdAt' => $when->toIso8601String(),
            'confirmedAt' => $when->toIso8601String(),
        ]), $cashier);

        app(LoyaltySpendEarningService::class)->processPaidOrder($order->fresh());
    }
}
