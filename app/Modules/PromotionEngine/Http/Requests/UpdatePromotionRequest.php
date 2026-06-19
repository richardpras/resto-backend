<?php

namespace App\Modules\PromotionEngine\Http\Requests;

use App\Models\Modules\PromotionEngine\Domain\Promotion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['sometimes', 'string', Rule::in(Promotion::TYPES)],
            'config' => ['sometimes', 'array'],
            'conditions' => ['nullable', 'array'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'isCombinable' => ['nullable', 'boolean'],
            'exclusive' => ['nullable', 'boolean'],
            'validFrom' => ['nullable', 'date'],
            'validUntil' => ['nullable', 'date'],
        ];
    }
}
