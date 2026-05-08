<?php

namespace App\Modules\Loyalty\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MergeCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'targetCustomerId' => ['required', 'integer', 'min:1'],
            'outletId' => ['required', 'integer', 'min:1', 'exists:outlets,id'],
        ];
    }
}
