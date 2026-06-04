<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaign;
use App\Models\Modules\LoyaltyEngine\Domain\MemberSegment;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
use App\Models\User;
use Database\Seeders\UserManagementPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Laravel\Passport\Passport;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyCampaignAudienceTest extends TestCase
{
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
        $this->seed(UserManagementPermissionsSeeder::class);
    }

    public function test_audience_preview_returns_dynamic_segment_members(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $vip = $this->createMember((int) $outlet->id);
        $other = $this->createMember((int) $outlet->id);
        $this->addTransaction($vip, 6000000);
        $this->addTransaction($other, 100000);

        $segmentId = (int) MemberSegment::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'VIP',
            'name' => 'VIP spenders',
            'segment_type' => MemberSegment::TYPE_VIP_SPENDER,
            'config_json' => ['minimum_spending' => 5000000],
            'is_active' => true,
        ])->id;

        $campaignId = (int) LoyaltyCampaign::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'VIP-CAMP',
            'name' => 'VIP campaign',
            'segment_id' => $segmentId,
            'campaign_type' => LoyaltyCampaign::TYPE_AUDIENCE,
            'status' => LoyaltyCampaign::STATUS_DRAFT,
        ])->id;

        $this->getJson("/api/v1/loyalty-campaigns/{$campaignId}/audience")
            ->assertOk()
            ->assertJsonPath('data.campaign.id', (string) $campaignId)
            ->assertJsonPath('data.segment.id', (string) $segmentId)
            ->assertJsonPath('data.memberCount', 1)
            ->assertJsonPath('data.members.0.id', (string) $vip->id);
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_campaign_audience__'],
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

    private function createOutlet(): Outlet
    {
        return Outlet::query()->create([
            'name' => 'Campaign Audience Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lcmpa-'.uniqid(),
        ]);
    }

    private function createMember(int $outletId): Member
    {
        return Member::query()->create([
            'outlet_id' => $outletId,
            'member_no' => 'M-'.uniqid(),
            'full_name' => 'Member '.uniqid(),
            'name' => 'Member',
            'phone' => '08'.random_int(1000000000, 9999999999),
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);
    }

    private function addTransaction(Member $member, float $amount): void
    {
        $order = Order::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $member->outlet_id,
            'code' => 'CAMP-'.uniqid(),
            'source' => 'pos',
            'order_type' => 'Takeaway',
            'status' => 'completed',
            'payment_status' => 'paid',
            'subtotal' => $amount,
            'tax' => 0,
            'total' => $amount,
            'member_id' => $member->id,
        ]);

        \App\Models\MemberTransaction::query()->create([
            'member_id' => $member->id,
            'order_id' => $order->id,
            'total_amount' => $amount,
            'transaction_at' => now(),
        ]);
    }
}
