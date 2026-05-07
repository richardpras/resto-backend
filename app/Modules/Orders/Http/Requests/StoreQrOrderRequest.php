<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreQrOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1'],
            'tableId' => ['required', 'integer', 'min:1'],
            'customerName' => ['nullable', 'string', 'max:255'],
            'expiresInMinutes' => ['nullable', 'integer', 'min:1', 'max:120'],
            'idempotencyKey' => ['sometimes', 'string', 'max:120'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.menuItemId' => ['required', 'integer', 'min:1'],
            'items.*.qty' => ['required', 'numeric', 'gt:0'],
            'items.*.notes' => ['nullable', 'string', 'max:255'],
        ];
    }
}
