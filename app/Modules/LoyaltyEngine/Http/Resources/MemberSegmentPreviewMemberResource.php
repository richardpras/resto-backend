<?php

namespace App\Modules\LoyaltyEngine\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberSegmentPreviewMemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (string) $this->id,
            'memberNo' => $this->member_no,
            'fullName' => $this->displayName(),
            'phone' => (string) $this->phone,
            'isActive' => (bool) $this->is_active,
        ];
    }
}
