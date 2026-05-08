<?php

namespace App\Modules\Loyalty\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListCustomersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['nullable', 'integer', 'min:1', 'exists:outlets,id'],
        ];
    }
}
