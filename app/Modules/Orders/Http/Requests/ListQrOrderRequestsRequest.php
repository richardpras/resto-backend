<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListQrOrderRequestsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:pending_cashier_confirmation,confirmed,rejected,expired'],
            'perPage' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
