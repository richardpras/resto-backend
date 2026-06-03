<?php

namespace App\Modules\Members\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RedeemMemberRewardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1'],
            'rewardId' => ['required', 'integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
