<?php

namespace App\Modules\Print\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListReceiptRenderHistoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1', 'exists:outlets,id'],
            'sourceType' => ['nullable', 'string', 'max:64'],
            'sourceId' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
