<?php

namespace App\Modules\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreIngredientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenantId' => ['nullable', 'integer', 'min:1'],
            'outletId' => ['nullable', 'integer', 'min:1'],
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', 'in:ingredient,atk,asset'],
            'unit' => ['required', 'string', 'max:20'],
            'stock' => ['nullable', 'numeric', 'min:0'],
            'min' => ['nullable', 'numeric', 'min:0'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
