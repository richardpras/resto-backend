<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyCampaignSnapshotResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array{campaign: mixed, capturedCount: int, members: mixed} $payload */
        $payload = $this->resource;

        return [
            'campaign' => new LoyaltyCampaignResource($payload['campaign']),
            'capturedCount' => (int) $payload['capturedCount'],
            'members' => MemberSegmentPreviewMemberResource::collection($payload['members']),
        ];
    }
}
