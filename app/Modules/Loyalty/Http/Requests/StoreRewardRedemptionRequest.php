<?php

namespace App\Modules\Loyalty\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreRewardRedemptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1', 'exists:outlets,id'],
            'idempotencyKey' => ['required', 'string', 'max:120'],
            'rewardCode' => ['required', 'string', 'max:64'],
            'pointsCost' => ['required', 'integer', 'min:1'],
            'clientOccurredAt' => ['nullable', 'date'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
