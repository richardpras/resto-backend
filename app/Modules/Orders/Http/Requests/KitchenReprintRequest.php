<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class KitchenReprintRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'orderItemIds' => ['required', 'array', 'min:1'],
            'orderItemIds.*' => ['required', 'integer', 'min:1'],
            'queuePrint' => ['sometimes', 'boolean'],
        ];
    }
}
