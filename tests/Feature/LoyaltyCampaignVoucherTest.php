<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaign;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaignAudience;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyVoucher;
use App\Models\Modules\LoyaltyEngine\Domain\MemberSegment;
use App\Models\Modules\LoyaltyEngine\Domain\MemberVoucher;
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

class LoyaltyCampaignVoucherTest extends TestCase
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

    public function test_campaign_issues_voucher_to_snapshot_audience(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $memberA = $this->createMember($outlet, 'A');
        $memberB = $this->createMember($outlet, 'B');
        $segmentId = (int) MemberSegment::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'NR',
            'name' => 'Never redeemed',
            'segment_type' => MemberSegment::TYPE_NEVER_REDEEMED,
            'config_json' => [],
            'is_active' => true,
        ])->id;

        $campaignId = (int) LoyaltyCampaign::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'PROMO',
            'name' => 'Promo',
            'segment_id' => $segmentId,
            'campaign_type' => LoyaltyCampaign::TYPE_AUDIENCE,
            'status' => LoyaltyCampaign::STATUS_ACTIVE,
            'activated_at' => now(),
        ])->id;

        LoyaltyCampaignAudience::query()->insert([
            [
                'campaign_id' => $campaignId,
                'member_id' => $memberA,
                'captured_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'campaign_id' => $campaignId,
                'member_id' => $memberB,
                'captured_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $voucherId = (int) LoyaltyVoucher::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'VIP',
            'name' => 'VIP Offer',
            'voucher_type' => LoyaltyVoucher::TYPE_CAMPAIGN,
            'value_type' => LoyaltyVoucher::VALUE_PERCENTAGE,
            'value' => 10,
            'is_active' => true,
        ])->id;

        $this->postJson("/api/v1/loyalty-campaigns/{$campaignId}/issue-voucher", [
            'voucherId' => $voucherId,
        ])
            ->assertOk()
            ->assertJsonPath('data.audienceCount', 2)
            ->assertJsonPath('data.issuedCount', 2)
            ->assertJsonPath('data.skippedCount', 0);

        $this->assertSame(2, MemberVoucher::query()->where('voucher_id', $voucherId)->count());
    }

    public function test_duplicate_campaign_voucher_issuance_skipped(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $memberId = $this->createMember($outlet);
        $segmentId = (int) MemberSegment::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'NR2',
            'name' => 'Never redeemed',
            'segment_type' => MemberSegment::TYPE_NEVER_REDEEMED,
            'config_json' => [],
            'is_active' => true,
        ])->id;

        $campaignId = (int) LoyaltyCampaign::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'PROMO2',
            'name' => 'Promo 2',
            'segment_id' => $segmentId,
            'campaign_type' => LoyaltyCampaign::TYPE_AUDIENCE,
            'status' => LoyaltyCampaign::STATUS_ACTIVE,
            'activated_at' => now(),
        ])->id;

        LoyaltyCampaignAudience::query()->create([
            'campaign_id' => $campaignId,
            'member_id' => $memberId,
            'captured_at' => now(),
        ]);

        $voucherId = (int) LoyaltyVoucher::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'DISC',
            'name' => 'Discount',
            'voucher_type' => LoyaltyVoucher::TYPE_CAMPAIGN,
            'value_type' => LoyaltyVoucher::VALUE_FIXED_AMOUNT,
            'value' => 5000,
            'is_active' => true,
        ])->id;

        $this->postJson("/api/v1/loyalty-campaigns/{$campaignId}/issue-voucher", [
            'voucherId' => $voucherId,
        ])->assertOk()->assertJsonPath('data.issuedCount', 1);

        $this->postJson("/api/v1/loyalty-campaigns/{$campaignId}/issue-voucher", [
            'voucherId' => $voucherId,
        ])
            ->assertOk()
            ->assertJsonPath('data.issuedCount', 0)
            ->assertJsonPath('data.skippedCount', 1);

        $this->assertSame(1, MemberVoucher::query()->where('voucher_id', $voucherId)->count());
    }

    public function test_campaign_without_snapshot_rejected(): void
    {
        $admin = $this->actingAsMembersManager();
        $outlet = $this->createOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $segmentId = (int) MemberSegment::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'NR3',
            'name' => 'Never redeemed',
            'segment_type' => MemberSegment::TYPE_NEVER_REDEEMED,
            'config_json' => [],
            'is_active' => true,
        ])->id;

        $campaignId = (int) LoyaltyCampaign::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'DRAFT',
            'name' => 'Draft',
            'segment_id' => $segmentId,
            'campaign_type' => LoyaltyCampaign::TYPE_AUDIENCE,
            'status' => LoyaltyCampaign::STATUS_DRAFT,
        ])->id;

        $voucherId = (int) LoyaltyVoucher::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'X',
            'name' => 'X',
            'voucher_type' => LoyaltyVoucher::TYPE_CAMPAIGN,
            'value_type' => LoyaltyVoucher::VALUE_FIXED_AMOUNT,
            'value' => 1000,
            'is_active' => true,
        ])->id;

        $this->postJson("/api/v1/loyalty-campaigns/{$campaignId}/issue-voucher", [
            'voucherId' => $voucherId,
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['status']);
    }

    private function actingAsMembersManager(): User
    {
        $this->seedUserManagementGatePermissions();
        $role = Role::query()->firstOrCreate(
            ['name' => '__test_campaign_voucher_admin__'],
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
            'name' => 'Campaign Voucher Outlet '.uniqid(),
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lcv-'.uniqid(),
        ]);
    }

    private function createMember(Outlet $outlet, string $suffix = ''): int
    {
        return (int) Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'M-'.$suffix.uniqid(),
            'full_name' => 'Member '.$suffix,
            'name' => 'Member '.$suffix,
            'phone' => '0814'.random_int(10000000, 99999999),
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ])->id;
    }
}
