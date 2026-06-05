<?php

namespace App\Modules\HR\Http\Resources;

use App\Models\Modules\HR\Domain\Pph21Bracket;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Pph21Bracket */
class Pph21BracketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'incomeFrom' => (float) $this->income_from,
            'incomeTo' => $this->income_to !== null ? (float) $this->income_to : null,
            'taxRate' => (float) $this->tax_rate,
        ];
    }
}
