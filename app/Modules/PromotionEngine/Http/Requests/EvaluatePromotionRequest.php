<?php

namespace App\Modules\PromotionEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EvaluatePromotionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1'],
            'subtotal' => ['required', 'numeric', 'min:0'],
            'items' => ['required', 'array'],
            'items.*.id' => ['required', 'string'],
            'items.*.price' => ['required', 'numeric', 'min:0'],
            'items.*.qty' => ['required', 'numeric', 'min:0'],
            'items.*.name' => ['nullable', 'string'],
            'items.*.category' => ['nullable', 'string'],
        ];
    }
}
