<?php

namespace App\Modules\UserManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PositionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => $this->outlet_id !== null ? (int) $this->outlet_id : null,
            'departmentId' => $this->department_id !== null ? (int) $this->department_id : null,
            'department' => $this->whenLoaded('department', fn () => new DepartmentResource($this->department)),
            'code' => $this->code,
            'name' => $this->name,
            'description' => $this->description,
            'sortOrder' => (int) $this->sort_order,
            'isActive' => (bool) $this->is_active,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
