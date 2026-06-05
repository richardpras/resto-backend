<?php

namespace App\Modules\Suppliers\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreSupplierRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'contact' => ['nullable', 'string', 'max:64'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string'],
            'status' => ['required', 'in:active,inactive'],
            'paymentTermDays' => ['nullable', 'integer', 'gte:0'],
            'leadTimeDays' => ['nullable', 'integer', 'gte:0'],
            'taxNumber' => ['nullable', 'string', 'max:64'],
            'taxName' => ['nullable', 'string', 'max:255'],
            'taxAddress' => ['nullable', 'string'],
            'contactPerson' => ['nullable', 'string', 'max:255'],
            'contactPhone' => ['nullable', 'string', 'max:64'],
            'contactEmail' => ['nullable', 'string', 'email', 'max:255'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }
}
