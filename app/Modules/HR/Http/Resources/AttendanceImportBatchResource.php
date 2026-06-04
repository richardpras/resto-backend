<?php

namespace App\Modules\HR\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AttendanceImportBatchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'filename' => $this->filename,
            'importedRows' => (int) $this->imported_rows,
            'importedAt' => $this->imported_at?->toIso8601String(),
            'createdBy' => $this->created_by,
            'createdAt' => $this->created_at?->toIso8601String(),
        ];
    }
}
