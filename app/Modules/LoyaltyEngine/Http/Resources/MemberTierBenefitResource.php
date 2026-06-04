<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberTierBenefitResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array{code: string, name: string} $benefit */
        $benefit = $this->resource;

        return [
            'code' => (string) $benefit['code'],
            'name' => (string) $benefit['name'],
        ];
    }
}
