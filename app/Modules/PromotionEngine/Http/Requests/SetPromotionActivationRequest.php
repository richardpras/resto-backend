<?php

namespace App\Modules\PromotionEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetPromotionActivationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'isActive' => ['required', 'boolean'],
        ];
    }
}
