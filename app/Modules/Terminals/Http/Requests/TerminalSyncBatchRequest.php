<?php

namespace App\Modules\Terminals\Http\Requests;

use App\Modules\Terminals\Support\TerminalOperationType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TerminalSyncBatchRequest extends FormRequest
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
            'deviceKey' => ['nullable', 'string', 'max:120'],
            'operations' => ['required', 'array', 'min:1', 'max:50'],
            'operations.*.fingerprint' => ['required', 'string', 'max:128'],
            'operations.*.operationType' => ['required', 'string', Rule::in(TerminalOperationType::all())],
            'operations.*.payload' => ['nullable', 'array'],
            'operations.*.clientOccurredAt' => ['nullable', 'date'],
        ];
    }
}
