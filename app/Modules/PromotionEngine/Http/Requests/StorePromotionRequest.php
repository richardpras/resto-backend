<?php

namespace App\Modules\PromotionEngine\Http\Requests;

use App\Models\Modules\PromotionEngine\Domain\Promotion;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'type' => ['required', 'string', Rule::in(Promotion::TYPES)],
            'config' => ['required', 'array'],
            'conditions' => ['nullable', 'array'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'isCombinable' => ['nullable', 'boolean'],
            'exclusive' => ['nullable', 'boolean'],
            'validFrom' => ['nullable', 'date'],
            'validUntil' => ['nullable', 'date'],
            'isActive' => ['nullable', 'boolean'],
        ];
    }
}
