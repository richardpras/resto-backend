<?php

namespace App\Modules\Members\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RedeemMemberPointsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1'],
            'points' => ['required', 'integer', 'min:1'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
