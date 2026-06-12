<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CallQrOrderCashierRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1'],
            'tableId' => ['required', 'integer', 'min:1'],
            'reason' => ['nullable', 'string', 'in:need_assistance,request_bill,order_question,other'],
        ];
    }
}
