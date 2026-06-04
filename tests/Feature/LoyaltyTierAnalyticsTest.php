<?php

namespace Tests\Feature;

use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyTier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\LoyaltyTierTestFixtures;
use Tests\Concerns\UserManagementApiFixture;
use Tests\TestCase;

class LoyaltyTierAnalyticsTest extends TestCase
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

    public function test_analytics_counts_and_summary(): void
    {
        $admin = $this->actingAsTierManager();
        $outlet = $this->createTierOutlet();
        $this->assignUserToOutlets($admin, [(int) $outlet->id]);

        $this->createTierApi($outlet, 'SILVER', LoyaltyTier::TYPE_LIFETIME_POINTS, ['minimum_points' => 1000], 10);
        $this->createTierApi($outlet, 'GOLD', LoyaltyTier::TYPE_LIFETIME_POINTS, ['minimum_points' => 5000], 20);

        $memberA = $this->createTierMember((int) $outlet->id, 'A');
        $memberB = $this->createTierMember((int) $outlet->id, 'B');

        $this->seedLifetimePoints($memberA, $outlet, 6000);
        $this->recalculateTier($memberA, $outlet);
        $this->seedLifetimePoints($memberB, $outlet, 1500);
        $this->recalculateTier($memberB, $outlet);

        $analytics = app(\App\Modules\LoyaltyEngine\Services\LoyaltyTierAnalyticsService::class)
            ->summary($admin, (int) $outlet->id);

        self::assertSame(2, $analytics['tiersCount']);
        self::assertCount(2, $analytics['tierSummary']);

        $gold = collect($analytics['tierSummary'])->firstWhere('tier.code', 'GOLD');
        $silver = collect($analytics['tierSummary'])->firstWhere('tier.code', 'SILVER');

        self::assertSame(1, $gold['memberCount'] ?? 0);
        self::assertSame(1, $silver['memberCount'] ?? 0);
    }
}
