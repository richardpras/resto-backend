<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberTierMembershipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \App\Models\Modules\LoyaltyEngine\Domain\LoyaltyTier $tier */
        $tier = $this->resource;

        return [
            'id' => (string) $tier->id,
            'code' => (string) $tier->code,
            'name' => (string) $tier->name,
        ];
    }
}
