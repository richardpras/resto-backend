<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreStockMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ingredient_id' => ['required', 'integer', 'min:1'],
            'movement_type' => ['required', 'in:in,out,adjustment'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'source' => ['required', 'string', 'max:100'],
            'reference_no' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
        ];
    }
}
