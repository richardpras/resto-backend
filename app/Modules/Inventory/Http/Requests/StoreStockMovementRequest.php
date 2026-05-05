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
            'inventory_item_id' => ['required', 'integer', 'min:1'],
            'type' => ['required', 'in:purchase,sale,adjustment,waste'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'source_type' => ['required', 'string', 'max:100'],
            'source_id' => ['nullable', 'string', 'max:100'],
        ];
    }
}
