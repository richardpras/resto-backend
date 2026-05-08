<?php

namespace App\Modules\Promotions\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ValidateCouponRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'exists:outlets,id'],
            'couponCode' => ['required', 'string', 'max:120'],
            'subtotal' => ['required', 'numeric', 'gte:0'],
        ];
    }
}
