<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListOrdersRequest extends FormRequest
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
            'perPage' => ['nullable', 'integer', 'min:1'],
            'paymentStatus' => ['nullable', 'string', 'max:50'],
            'orderType' => ['nullable', 'string', 'max:50'],
            'status' => ['nullable', 'string', 'max:50'],
            'source' => ['nullable', 'string', 'max:50'],
            'serviceMode' => ['nullable', 'in:dine_in,takeaway'],
            'kitchenStatus' => ['nullable', 'string', 'max:50'],
        ];
    }
}
