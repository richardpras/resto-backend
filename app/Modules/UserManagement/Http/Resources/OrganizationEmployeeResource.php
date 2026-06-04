<?php

namespace App\Modules\UserManagement\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrganizationEmployeeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => $this->outlet_id !== null ? (int) $this->outlet_id : null,
            'outlet' => $this->whenLoaded('outletRelation', fn () => [
                'id' => (int) $this->outletRelation->id,
                'code' => $this->outletRelation->code,
                'name' => $this->outletRelation->name,
            ]),
            'employeeNo' => $this->employee_no,
            'fullName' => $this->full_name,
            'email' => $this->email,
            'phone' => $this->phone,
            'gender' => $this->gender,
            'birthDate' => $this->birth_date?->toDateString(),
            'hireDate' => $this->hire_date?->toDateString(),
            'status' => $this->status,
            'positionId' => $this->position_id !== null ? (int) $this->position_id : null,
            'position' => $this->whenLoaded('positionRelation', fn () => new PositionResource($this->positionRelation)),
            'positionName' => $this->relationLoaded('positionRelation')
                ? $this->positionRelation?->name
                : $this->position,
            'departmentId' => $this->department_id !== null ? (int) $this->department_id : null,
            'department' => $this->whenLoaded('department', fn () => new DepartmentResource($this->department)),
            'userId' => $this->user_id !== null ? (int) $this->user_id : null,
            'linkedUser' => $this->whenLoaded('user', fn () => $this->user === null ? null : [
                'id' => (int) $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'notes' => $this->notes,
            'createdAt' => $this->created_at?->toIso8601String(),
            'updatedAt' => $this->updated_at?->toIso8601String(),
        ];
    }
}
