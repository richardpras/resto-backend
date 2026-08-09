<?php

namespace App\Modules\Settings\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Modules\Settings\Domain\OutletReservationSetting */
class OutletReservationSettingResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'outletId' => (int) $this->outlet_id,
            'publicEnabled' => (bool) $this->public_enabled,
            'publicSlug' => (string) $this->public_slug,
            'depositMode' => (string) $this->deposit_mode,
            'depositPercent' => $this->deposit_percent !== null ? (float) $this->deposit_percent : null,
            'depositFlatAmount' => $this->deposit_flat_amount !== null ? (float) $this->deposit_flat_amount : null,
            'preorderRequired' => (bool) $this->preorder_required,
            'depositInstructions' => $this->deposit_instructions,
            'depositReviewTimeoutHours' => $this->deposit_review_timeout_hours,
            'inviteLinkExpiryHours' => (int) ($this->invite_link_expiry_hours ?? 24),
            'publicUrlPath' => '/reserve/'.$this->public_slug,
        ];
    }
}
