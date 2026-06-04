<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LoyaltyCampaignResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'outletId' => (int) $this->outlet_id,
            'code' => (string) $this->code,
            'name' => (string) $this->name,
            'description' => $this->description,
            'segmentId' => (string) $this->segment_id,
            'segment' => $this->whenLoaded('segment', fn () => new MemberSegmentResource($this->segment)),
            'campaignType' => (string) $this->campaign_type,
            'scheduledAt' => $this->scheduled_at?->toIso8601String(),
            'status' => (string) $this->status,
            'audienceCount' => (int) ($this->audience_count ?? 0),
            'capturedCount' => (int) ($this->captured_count ?? $this->audienceSnapshots_count ?? 0),
            'issuedVoucherCount' => (int) ($this->issued_voucher_count ?? 0),
            'activatedAt' => $this->activated_at?->toIso8601String(),
            'completedAt' => $this->completed_at?->toIso8601String(),
            'cancelledAt' => $this->cancelled_at?->toIso8601String(),
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
