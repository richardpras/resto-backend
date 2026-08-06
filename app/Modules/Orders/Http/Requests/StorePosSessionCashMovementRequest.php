<?php

namespace App\Modules\Orders\Http\Requests;

use App\Models\Modules\Orders\Domain\PosSessionCashMovement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePosSessionCashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $allCategories = array_values(array_unique([
            ...PosSessionCashMovement::OUT_CATEGORIES,
            ...PosSessionCashMovement::IN_CATEGORIES,
        ]));

        return [
            'direction' => ['required', 'string', Rule::in([
                PosSessionCashMovement::DIRECTION_IN,
                PosSessionCashMovement::DIRECTION_OUT,
            ])],
            'amount' => ['required', 'numeric', 'gt:0'],
            'category' => ['required', 'string', Rule::in($allCategories)],
            'notes' => ['nullable', 'string', 'max:500'],
            'occurredAt' => ['nullable', 'date'],
            'idempotencyKey' => ['sometimes', 'string', 'max:120'],
            'clientLocalRef' => ['sometimes', 'nullable', 'string', 'max:120'],
        ];
    }
}
