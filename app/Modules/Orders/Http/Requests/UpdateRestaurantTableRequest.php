<?php

namespace App\Modules\Orders\Http\Requests;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateRestaurantTableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var RestaurantTable $table */
        $table = $this->route('table');
        $outletId = (int) $table->outlet_id;

        return [
            'code' => [
                'sometimes',
                'nullable',
                'string',
                'max:64',
                Rule::unique('tables', 'code')
                    ->where(fn ($q) => $q->where('outlet_id', $outletId))
                    ->ignore($table->id),
            ],
            'name' => [
                'sometimes',
                'required',
                'string',
                'max:120',
                Rule::unique('tables', 'name')
                    ->where(fn ($q) => $q->where('outlet_id', $outletId))
                    ->ignore($table->id),
            ],
            'capacity' => ['nullable', 'integer', 'min:0', 'max:999'],
            'zone' => ['sometimes', 'nullable', 'string', 'max:120'],
            'status' => ['sometimes', 'required', 'in:active,inactive'],
            'active' => ['sometimes', 'boolean'],
        ];
    }
}
