<?php

namespace Tests\Feature;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyTier;
use App\Models\Modules\LoyaltyEngine\Domain\MemberTierHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\LoyaltyTierTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyTierTest extends TestCase
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

    public function test_points_qualification_assigns_tier(): void
    {
        $admin = $this->actingAsTierManager();
        $outlet = $this->createTierOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createTierMember((int) $outlet->id, 'Points Member');

        $this->createTierApi($outlet, 'SILVER', LoyaltyTier::TYPE_LIFETIME_POINTS, ['minimum_points' => 1000], 10);
        $this->seedLifetimePoints($member, $outlet, 1500);
        $this->recalculateTier($member, $outlet);

        $assignment = app(\App\Modules\LoyaltyEngine\Services\LoyaltyTierService::class)
            ->currentAssignment((int) $member->id, (int) $outlet->id);

        self::assertNotNull($assignment);
        self::assertSame('SILVER', $assignment->tier?->code);
    }

    public function test_spending_qualification_assigns_tier(): void
    {
        $admin = $this->actingAsTierManager();
        $outlet = $this->createTierOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createTierMember((int) $outlet->id, 'Spender');

        $this->createTierApi($outlet, 'VIP', LoyaltyTier::TYPE_LIFETIME_SPENDING, ['minimum_spending' => 5000000], 20);
        $this->addMemberTransaction($member, 6000000);
        $this->recalculateTier($member, $outlet);

        $assignment = app(\App\Modules\LoyaltyEngine\Services\LoyaltyTierService::class)
            ->currentAssignment((int) $member->id, (int) $outlet->id);

        self::assertSame('VIP', $assignment?->tier?->code);
    }

    public function test_visit_qualification_assigns_tier(): void
    {
        $admin = $this->actingAsTierManager();
        $outlet = $this->createTierOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createTierMember((int) $outlet->id, 'Frequent');

        $this->createTierApi($outlet, 'REG', LoyaltyTier::TYPE_VISIT_COUNT, ['minimum_visits' => 20], 5);
        for ($i = 0; $i < 20; $i++) {
            $this->addMemberTransaction($member, 10000);
        }
        $this->recalculateTier($member, $outlet);

        $assignment = app(\App\Modules\LoyaltyEngine\Services\LoyaltyTierService::class)
            ->currentAssignment((int) $member->id, (int) $outlet->id);

        self::assertSame('REG', $assignment?->tier?->code);
    }

    public function test_highest_qualified_tier_wins(): void
    {
        $admin = $this->actingAsTierManager();
        $outlet = $this->createTierOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createTierMember((int) $outlet->id, 'High Points');

        $this->createTierApi($outlet, 'SILVER', LoyaltyTier::TYPE_LIFETIME_POINTS, ['minimum_points' => 1000], 10);
        $this->createTierApi($outlet, 'GOLD', LoyaltyTier::TYPE_LIFETIME_POINTS, ['minimum_points' => 5000], 20);
        $this->seedLifetimePoints($member, $outlet, 6000);
        $this->recalculateTier($member, $outlet);

        $assignment = app(\App\Modules\LoyaltyEngine\Services\LoyaltyTierService::class)
            ->currentAssignment((int) $member->id, (int) $outlet->id);

        self::assertSame('GOLD', $assignment?->tier?->code);
    }

    public function test_outlet_isolation_blocks_foreign_tier_access(): void
    {
        $admin = $this->actingAsTierManager();
        $outletA = $this->createTierOutlet('A');
        $outletB = $this->createTierOutlet('B');
        $this->assignUserToOutlets($admin, [(int) $outletA->id]);

        $foreignTierId = (int) LoyaltyTier::query()->create([
            'outlet_id' => $outletB->id,
            'code' => 'FOREIGN',
            'name' => 'Foreign',
            'qualification_type' => LoyaltyTier::TYPE_LIFETIME_POINTS,
            'qualification_config' => ['minimum_points' => 100],
            'sort_order' => 1,
            'is_active' => true,
        ])->id;

        $this->getJson('/api/v1/loyalty-tiers/'.$foreignTierId)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['outletId']);
    }

    public function test_activation_filtering_excludes_inactive_tiers(): void
    {
        $admin = $this->actingAsTierManager();
        $outlet = $this->createTierOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createTierMember((int) $outlet->id, 'Inactive Tier');

        $tierId = $this->createTierApi($outlet, 'BRONZE', LoyaltyTier::TYPE_LIFETIME_POINTS, ['minimum_points' => 100], 5);
        $this->seedLifetimePoints($member, $outlet, 500);
        $this->recalculateTier($member, $outlet);

        self::assertSame('BRONZE', app(\App\Modules\LoyaltyEngine\Services\LoyaltyTierService::class)
            ->currentAssignment((int) $member->id, (int) $outlet->id)?->tier?->code);

        $this->patchJson("/api/v1/loyalty-tiers/{$tierId}/activation", ['isActive' => false])->assertOk();

        $this->recalculateTier($member, $outlet);

        self::assertNull(app(\App\Modules\LoyaltyEngine\Services\LoyaltyTierService::class)
            ->currentAssignment((int) $member->id, (int) $outlet->id));
    }
}
