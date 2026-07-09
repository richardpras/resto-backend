<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveDailyStocktakeCountsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.ingredientId' => ['required', 'integer', 'min:1'],
            'lines.*.openingQty' => ['sometimes', 'numeric', 'min:0'],
            'lines.*.closingQty' => ['sometimes', 'numeric', 'min:0'],
        ];
    }
}
