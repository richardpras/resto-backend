<?php

namespace App\Modules\System\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin array<string, mixed> */
class FailedJobResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $row = is_array($this->resource) ? $this->resource : [];

        return [
            'id' => (int) ($row['id'] ?? 0),
            'uuid' => (string) ($row['uuid'] ?? ''),
            'connection' => (string) ($row['connection'] ?? ''),
            'queue' => (string) ($row['queue'] ?? ''),
            'jobClass' => (string) ($row['jobClass'] ?? ''),
            'module' => (string) ($row['module'] ?? ''),
            'jobSeverity' => (string) ($row['jobSeverity'] ?? ''),
            'exceptionPreview' => (string) ($row['exceptionPreview'] ?? ''),
            'failedAt' => $row['failedAt'] ?? null,
            'ageMinutes' => (int) ($row['ageMinutes'] ?? 0),
            'outletId' => $row['outletId'] ?? null,
        ];
    }
}
