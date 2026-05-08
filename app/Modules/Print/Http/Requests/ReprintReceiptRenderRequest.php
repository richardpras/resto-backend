<?php

namespace App\Modules\Print\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReprintReceiptRenderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
