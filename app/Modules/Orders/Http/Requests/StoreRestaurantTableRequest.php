<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreRestaurantTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $outletId = (int) $this->input('outletId', 0);

        return [
            'outletId' => ['required', 'integer', 'min:1', 'exists:outlets,id'],
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('tables', 'name')->where(fn ($q) => $q->where('outlet_id', $outletId)),
            ],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:999'],
            'status' => ['required', 'in:active,inactive'],
        ];
    }
}
