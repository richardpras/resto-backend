<?php

namespace App\Modules\Terminals\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TerminalHeartbeatRequest extends FormRequest
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
            'sessionMetadata' => ['nullable', 'array'],
        ];
    }
}
