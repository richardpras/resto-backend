<?php

namespace App\Modules\Hardware\Http\Requests;

use App\Modules\Hardware\Support\HardwareCommandType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EnqueueHardwareCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1', 'exists:outlets,id'],
            'deviceKey' => ['required', 'string', 'max:120'],
            'sessionId' => ['nullable', 'integer', 'min:1'],
            'commandType' => ['required', 'string', Rule::in(HardwareCommandType::all())],
            'idempotencyKey' => ['required', 'string', 'max:128'],
            'payload' => ['nullable', 'array'],
            'maxRetries' => ['nullable', 'integer', 'min:0', 'max:25'],
            'nextRetryAt' => ['nullable', 'date'],
        ];
    }
}
