<?php

namespace App\Modules\Payments\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PaymentWebhookRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'externalReference' => ['required', 'string', 'max:120'],
            'status' => ['required', 'in:pending,authorized,paid,failed,expired,cancelled,refunded'],
            'paymentMethod' => ['nullable', 'string', 'max:64'],
            'eventId' => ['nullable', 'string', 'max:120'],
            'occurredAt' => ['nullable', 'date'],
            'payload' => ['nullable', 'array'],
        ];
    }
}
