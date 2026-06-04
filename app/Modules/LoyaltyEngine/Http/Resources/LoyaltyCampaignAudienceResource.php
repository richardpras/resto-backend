<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyCampaignAudienceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array{campaign: mixed, segment: mixed, memberCount: int, members: mixed} $payload */
        $payload = $this->resource;

        return [
            'campaign' => new LoyaltyCampaignResource($payload['campaign']),
            'segment' => new MemberSegmentResource($payload['segment']),
            'memberCount' => (int) $payload['memberCount'],
            'members' => MemberSegmentPreviewMemberResource::collection($payload['members']),
        ];
    }
}
