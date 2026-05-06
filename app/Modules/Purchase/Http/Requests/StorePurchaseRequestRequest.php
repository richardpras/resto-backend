<?php

namespace App\Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => ['required', 'date'],
            'outlet' => ['nullable', 'string', 'max:100'],
            'requestedBy' => ['required', 'string', 'max:100'],
            'status' => ['required', Rule::in(['draft', 'submitted', 'approved', 'rejected'])],
            'notes' => ['nullable', 'string'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.inventoryItemId' => ['required', 'integer', 'exists:ingredients,id'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.unit' => ['nullable', 'string', 'max:20'],
            'items.*.notes' => ['nullable', 'string'],
        ];
    }
}
