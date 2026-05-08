<?php

namespace App\Modules\Print\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RetryPrintJobRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'exists:outlets,id'],
        ];
    }
}
