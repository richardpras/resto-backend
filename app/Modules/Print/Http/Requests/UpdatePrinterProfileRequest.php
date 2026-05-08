<?php

namespace App\Modules\Print\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePrinterProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'station' => ['nullable', 'string', 'max:64'],
            'connectionType' => ['nullable', 'string', Rule::in(['agent', 'lan', 'bluetooth', 'usb', 'unknown'])],
            'deviceIdentifier' => ['nullable', 'string', 'max:190'],
            'ipAddress' => ['nullable', 'ip'],
            'macAddress' => ['nullable', 'string', 'max:32'],
            'bluetoothName' => ['nullable', 'string', 'max:120'],
            'bluetoothAddress' => ['nullable', 'string', 'max:32'],
            'pairingState' => ['nullable', 'string', 'max:32'],
            'lastConnectedAt' => ['nullable', 'date'],
            'reconnectMetadata' => ['nullable', 'array'],
            'signalMetadata' => ['nullable', 'array'],
            'endpoint' => ['nullable', 'string', 'max:255'],
            'isActive' => ['nullable', 'boolean'],
            'retryPolicy' => ['nullable', 'array'],
            'meta' => ['nullable', 'array'],
        ];
    }
}
