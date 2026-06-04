<?php

namespace App\Modules\LoyaltyEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetLoyaltyAutomationActivationRequest extends FormRequest
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
