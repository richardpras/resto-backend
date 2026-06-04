<?php

namespace Tests\Feature;

use App\Models\Member;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaign;
use App\Models\Modules\LoyaltyEngine\Domain\LoyaltyCampaignAudience;
use App\Models\Modules\LoyaltyEngine\Domain\MemberSegment;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class LoyaltyCampaignSchedulerTest extends TestCase
{
    use RefreshDatabase;

    public function test_scheduler_activates_due_scheduled_campaigns(): void
    {
        Carbon::setTestNow('2026-06-15 12:00:00');

        $outlet = Outlet::query()->create([
            'name' => 'Sched Outlet',
            'address' => '',
            'phone' => '',
            'manager' => '',
            'status' => 'active',
            'code' => 'lcsched-'.uniqid(),
        ]);

        Member::query()->create([
            'outlet_id' => $outlet->id,
            'member_no' => 'M-SCHED',
            'full_name' => 'Sched Member',
            'name' => 'Sched Member',
            'phone' => '081499999999',
            'is_active' => true,
            'status' => 'active',
            'points' => 0,
        ]);

        $segmentId = (int) MemberSegment::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'NR-S',
            'name' => 'Never redeemed',
            'segment_type' => MemberSegment::TYPE_NEVER_REDEEMED,
            'config_json' => [],
            'is_active' => true,
        ])->id;

        $campaignId = (int) LoyaltyCampaign::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'SCHED1',
            'name' => 'Scheduled campaign',
            'segment_id' => $segmentId,
            'campaign_type' => LoyaltyCampaign::TYPE_AUDIENCE,
            'scheduled_at' => '2026-06-15 10:00:00',
            'status' => LoyaltyCampaign::STATUS_SCHEDULED,
        ])->id;

        $futureId = (int) LoyaltyCampaign::query()->create([
            'outlet_id' => $outlet->id,
            'code' => 'SCHED2',
            'name' => 'Future campaign',
            'segment_id' => $segmentId,
            'campaign_type' => LoyaltyCampaign::TYPE_AUDIENCE,
            'scheduled_at' => '2026-06-16 10:00:00',
            'status' => LoyaltyCampaign::STATUS_SCHEDULED,
        ])->id;

        Artisan::call('loyalty:process-campaigns');

        $this->assertDatabaseHas('loyalty_campaigns', [
            'id' => $campaignId,
            'status' => LoyaltyCampaign::STATUS_ACTIVE,
        ]);
        $this->assertNotNull(LoyaltyCampaign::query()->find($campaignId)?->activated_at);
        $this->assertSame(1, LoyaltyCampaignAudience::query()->where('campaign_id', $campaignId)->count());

        $this->assertDatabaseHas('loyalty_campaigns', [
            'id' => $futureId,
            'status' => LoyaltyCampaign::STATUS_SCHEDULED,
        ]);

        Carbon::setTestNow();
    }
}
