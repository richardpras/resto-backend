<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SyncOrderSplitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'persons' => ['required', 'array', 'min:1'],
            'persons.*.splitType' => ['required', 'in:by_item,by_person,mixed'],
            'persons.*.label' => ['required', 'string', 'max:120'],
            'persons.*.items' => ['required', 'array', 'min:1'],
            'persons.*.items.*.orderItemId' => ['required', 'integer', 'exists:order_items,id'],
            'persons.*.items.*.qty' => ['required', 'numeric', 'gt:0'],
            'persons.*.items.*.amount' => ['required', 'numeric', 'gt:0'],
            'expectedUpdatedAt' => ['sometimes', 'date'],
            'idempotencyKey' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
