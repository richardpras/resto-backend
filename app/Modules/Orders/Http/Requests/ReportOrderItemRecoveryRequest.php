<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReportOrderItemRecoveryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'targetStatus' => [
                'required',
                'string',
                'max:64',
            ],
            'reason' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
