<?php

namespace App\Modules\Kitchen\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateKitchenTicketStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'in:queued,in_progress,ready,served,cancelled'],
            'expectedUpdatedAt' => ['sometimes', 'date'],
            'idempotencyKey' => ['sometimes', 'string', 'max:120'],
        ];
    }
}
