<?php

namespace Tests\Feature;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyTier;
use App\Models\Modules\LoyaltyEngine\Domain\MemberTierHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\LoyaltyTierTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyTierHistoryTest extends TestCase
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

    public function test_history_entry_created_on_assignment(): void
    {
        $admin = $this->actingAsTierManager();
        $outlet = $this->createTierOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createTierMember((int) $outlet->id, 'History Member');

        $this->createTierApi($outlet, 'SILVER', LoyaltyTier::TYPE_LIFETIME_POINTS, ['minimum_points' => 1000], 10);
        $this->seedLifetimePoints($member, $outlet, 1200);
        $this->recalculateTier($member, $outlet);

        $this->assertDatabaseHas('member_tier_histories', [
            'member_id' => $member->id,
            'outlet_id' => $outlet->id,
            'reason' => MemberTierHistory::REASON_RECALCULATION,
            'removed_at' => null,
        ]);
    }

    public function test_reassignment_preserves_previous_history(): void
    {
        $admin = $this->actingAsTierManager();
        $outlet = $this->createTierOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);
        $member = $this->createTierMember((int) $outlet->id, 'Upgrade Member');

        $this->createTierApi($outlet, 'SILVER', LoyaltyTier::TYPE_LIFETIME_POINTS, ['minimum_points' => 1000], 10);
        $this->createTierApi($outlet, 'GOLD', LoyaltyTier::TYPE_LIFETIME_POINTS, ['minimum_points' => 5000], 20);

        $this->seedLifetimePoints($member, $outlet, 1200);
        $this->recalculateTier($member, $outlet);

        $this->seedLifetimePoints($member, $outlet, 5000);
        $this->recalculateTier($member, $outlet);

        self::assertSame(2, MemberTierHistory::query()->where('member_id', $member->id)->count());
        self::assertSame(1, MemberTierHistory::query()->where('member_id', $member->id)->whereNull('removed_at')->count());
        self::assertSame(1, MemberTierHistory::query()->where('member_id', $member->id)->whereNotNull('removed_at')->count());

        $this->getJson("/api/v1/members/{$member->id}/profile?outletId={$outlet->id}")
            ->assertOk()
            ->assertJsonPath('data.tier.code', 'GOLD')
            ->assertJsonCount(2, 'data.tierHistory');
    }
}
