<?php

namespace App\Modules\Suppliers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSupplierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'contact' => ['sometimes', 'nullable', 'string', 'max:64'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'address' => ['sometimes', 'nullable', 'string', 'max:500'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'status' => ['sometimes', 'in:active,inactive'],
            'paymentTermDays' => ['sometimes', 'nullable', 'integer', 'gte:0'],
            'leadTimeDays' => ['sometimes', 'nullable', 'integer', 'gte:0'],
            'taxNumber' => ['sometimes', 'nullable', 'string', 'max:64'],
            'taxName' => ['sometimes', 'nullable', 'string', 'max:255'],
            'taxAddress' => ['sometimes', 'nullable', 'string'],
            'contactPerson' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contactPhone' => ['sometimes', 'nullable', 'string', 'max:64'],
            'contactEmail' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}
