<?php

namespace App\Modules\Members\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MemberResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $fullName = $this->full_name ?: $this->name;
        $isActive = $this->is_active ?? (($this->status ?? 'active') === 'active');

        return [
            'id' => (string) $this->id,
            'outletId' => $this->outlet_id !== null ? (int) $this->outlet_id : null,
            'memberNo' => $this->member_no,
            'fullName' => $fullName,
            'name' => $fullName,
            'phone' => $this->phone,
            'email' => $this->email,
            'birthDate' => ($this->birth_date ?? $this->birthday)?->format('Y-m-d'),
            'birthday' => ($this->birth_date ?? $this->birthday)?->format('Y-m-d'),
            'gender' => $this->gender,
            'notes' => $this->notes,
            'isActive' => (bool) $isActive,
            'status' => $isActive ? 'active' : 'inactive',
            'points' => 0,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
