<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ApplyOrderVoucherRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'memberVoucherId' => ['required', 'integer', 'min:1', 'exists:member_vouchers,id'],
        ];
    }
}
