<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\Pph21Config;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Pph21Config */
class Pph21ConfigResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'effectiveDate' => $this->effective_date?->toDateString(),
            'ptkpTk0' => (float) $this->ptkp_tk0,
            'ptkpTk1' => (float) $this->ptkp_tk1,
            'ptkpTk2' => (float) $this->ptkp_tk2,
            'ptkpTk3' => (float) $this->ptkp_tk3,
            'ptkpK0' => (float) $this->ptkp_k0,
            'ptkpK1' => (float) $this->ptkp_k1,
            'ptkpK2' => (float) $this->ptkp_k2,
            'ptkpK3' => (float) $this->ptkp_k3,
            'isActive' => (bool) $this->is_active,
            'brackets' => Pph21BracketResource::collection($this->whenLoaded('brackets')),
        ];
    }
}
