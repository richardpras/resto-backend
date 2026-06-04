<?php

namespace Tests\Feature;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\LoyaltyTierTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyTierBenefitTest extends TestCase
{
    use LoyaltyTierTestFixtures;
    use RefreshDatabase;
    use UserManagementApiFixture;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
        Artisan::call('passport:keys', ['--force' => true]);
    }

    public function test_save_benefits_on_tier_create_and_update(): void
    {
        $admin = $this->actingAsTierManager();
        $outlet = $this->createTierOutlet('benefits');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $create = $this->postJson('/api/v1/loyalty-tiers', [
            'outletId' => $outlet->id,
            'code' => 'GOLD',
            'name' => 'Gold',
            'qualificationType' => LoyaltyTier::TYPE_LIFETIME_POINTS,
            'qualificationConfig' => ['minimum_points' => 5000],
            'benefitConfig' => [
                'priorityCampaign' => true,
                'exclusiveVoucher' => true,
                'exclusiveReward' => true,
                'monthlyVoucher' => false,
            ],
            'sortOrder' => 20,
            'isActive' => true,
        ])
            ->assertCreated()
            ->json('data');

        self::assertTrue($create['benefitConfig']['priorityCampaign']);
        self::assertTrue($create['benefitConfig']['exclusiveReward']);
        self::assertFalse($create['benefitConfig']['monthlyVoucher']);

        $this->patchJson('/api/v1/loyalty-tiers/'.$create['id'], [
            'benefitConfig' => [
                'priorityCampaign' => true,
                'exclusiveVoucher' => false,
                'exclusiveReward' => true,
                'monthlyVoucher' => true,
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.benefitConfig.monthlyVoucher', true)
            ->assertJsonPath('data.benefitConfig.exclusiveVoucher', false);
    }

    public function test_profile_returns_member_tier_benefits(): void
    {
        $admin = $this->actingAsTierManager();
        $outlet = $this->createTierOutlet('profile-benefits');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createTierMember((int) $outlet->id, 'Benefit Member');

        $this->postJson('/api/v1/loyalty-tiers', [
            'outletId' => $outlet->id,
            'code' => 'SILVER',
            'name' => 'Silver',
            'qualificationType' => LoyaltyTier::TYPE_LIFETIME_POINTS,
            'qualificationConfig' => ['minimum_points' => 100],
            'benefitConfig' => [
                'priorityCampaign' => true,
                'exclusiveVoucher' => false,
                'exclusiveReward' => false,
                'monthlyVoucher' => false,
            ],
            'sortOrder' => 10,
            'isActive' => true,
        ])->assertCreated();

        $this->seedLifetimePoints($member, $outlet, 500);
        $this->recalculateTier($member, $outlet);

        $this->getJson('/api/v1/members/'.$member->id.'/profile?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.tier.code', 'SILVER')
            ->assertJsonPath('data.benefits.0.code', 'priority_campaign')
            ->assertJsonPath('data.benefits.0.name', 'Priority Campaign Access');
    }

    public function test_analytics_includes_tier_benefit_summary(): void
    {
        $admin = $this->actingAsTierManager();
        $outlet = $this->createTierOutlet('analytics-benefits');
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createTierMember((int) $outlet->id, 'Analytics Member');

        $this->postJson('/api/v1/loyalty-tiers', [
            'outletId' => $outlet->id,
            'code' => 'GOLD',
            'name' => 'Gold',
            'qualificationType' => LoyaltyTier::TYPE_LIFETIME_POINTS,
            'qualificationConfig' => ['minimum_points' => 100],
            'benefitConfig' => [
                'priorityCampaign' => true,
                'exclusiveVoucher' => true,
                'exclusiveReward' => false,
                'monthlyVoucher' => false,
            ],
            'sortOrder' => 20,
            'isActive' => true,
        ])->assertCreated();

        $this->seedLifetimePoints($member, $outlet, 500);
        $this->recalculateTier($member, $outlet);

        $this->getJson('/api/v1/loyalty-engine/analytics?outletId='.$outlet->id)
            ->assertOk()
            ->assertJsonPath('data.tierBenefitSummary.0.tier', 'Gold')
            ->assertJsonPath('data.tierBenefitSummary.0.members', 1)
            ->assertJsonPath('data.tierBenefitSummary.0.benefits', 2);
    }

    public function test_outlet_isolation_blocks_foreign_tier_benefit_update(): void
    {
        $admin = $this->actingAsTierManager();
        $outletA = $this->createTierOutlet('iso-a');
        $outletB = $this->createTierOutlet('iso-b');
        $this->assignUserToOutlets($admin, [(int) $outletA->id]);

        $tierId = (int) LoyaltyTier::query()->create([
            'outlet_id' => $outletB->id,
            'code' => 'VIP',
            'name' => 'VIP',
            'qualification_type' => LoyaltyTier::TYPE_LIFETIME_POINTS,
            'qualification_config' => ['minimum_points' => 10000],
            'benefit_config_json' => [
                'priorityCampaign' => true,
                'exclusiveVoucher' => false,
                'exclusiveReward' => false,
                'monthlyVoucher' => false,
            ],
            'sort_order' => 30,
            'is_active' => true,
        ])->id;

        $this->patchJson('/api/v1/loyalty-tiers/'.$tierId, [
            'benefitConfig' => ['monthlyVoucher' => true],
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId']);
    }
}
