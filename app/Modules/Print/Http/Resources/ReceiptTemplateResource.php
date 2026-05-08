<?php

namespace App\Modules\Print\Http\Resources;

use App\Models\Modules\Print\Domain\ReceiptTemplate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin ReceiptTemplate
 */
class ReceiptTemplateResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'kind' => (string) $this->kind,
            'code' => (string) $this->code,
            'version' => (int) $this->version,
            'name' => (string) $this->name,
            'thermalWidthChars' => (int) $this->thermal_width_chars,
            'printerProfileId' => $this->printer_profile_id !== null ? (int) $this->printer_profile_id : null,
            'sections' => $this->sections,
            'defaults' => $this->defaults,
            'isActive' => (bool) $this->is_active,
            'isDefaultFallback' => (bool) $this->is_default_fallback,
        ];
    }
}
