<?php

namespace App\Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmployeeScheduleResource extends JsonResource
{
    /**
     * @param  array{weekStart: string, weekEnd: string, days: list<array<string, mixed>>}  $resource
     */
    public function toArray(Request $request): array
    {
        return $this->resource;
    }
}
