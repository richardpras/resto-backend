<?php

namespace App\Modules\GiftCards\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CheckGiftCardRequest extends FormRequest
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
