<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\EmployeeShiftAssignment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class EmployeeShiftHistoryResource extends JsonResource
{
    /**
     * @param  array{current: ?EmployeeShiftAssignment, history: Collection<int, EmployeeShiftAssignment>}  $resource
     */
    public function toArray(Request $request): array
    {
        /** @var array{current: ?EmployeeShiftAssignment, history: Collection<int, EmployeeShiftAssignment>} $payload */
        $payload = $this->resource;

        return [
            'current' => $payload['current'] !== null
                ? new EmployeeShiftAssignmentResource($payload['current'])
                : null,
            'history' => EmployeeShiftAssignmentResource::collection($payload['history']),
        ];
    }
}
