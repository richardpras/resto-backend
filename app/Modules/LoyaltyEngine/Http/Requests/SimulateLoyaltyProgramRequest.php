<?php

namespace App\Modules\LoyaltyEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SimulateLoyaltyProgramRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1'],
            'programId' => ['required', 'integer', 'min:1'],
            'spendingAmount' => ['nullable', 'numeric', 'min:0'],
            'visitCount' => ['nullable', 'integer', 'min:0'],
            'simulationDate' => ['nullable', 'date'],
        ];
    }
}
