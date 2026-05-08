<?php

namespace App\Modules\Hardware\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class HardwareHeartbeatRequest extends FormRequest
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
            'status' => ['nullable', 'string', 'max:32'],
            'runtimeState' => ['nullable', 'string', 'in:connected,disconnected,reconnecting,stale,recovering,degraded'],
            'transports' => ['nullable', 'array'],
            'transports.*' => ['string', 'max:50'],
            'capabilities' => ['nullable', 'array'],
            'spoolSupported' => ['nullable', 'boolean'],
            'queueDepth' => ['nullable', 'integer', 'min:0'],
            'reconnectMetadata' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
            'metadata.watchdog' => ['nullable', 'array'],
            'metadata.spoolHealth' => ['nullable', 'array'],
            'metadata.updates' => ['nullable', 'array'],
            'metadata.deployment' => ['nullable', 'array'],
            'metadata.auth' => ['nullable', 'array'],
            'metadata.provisioning' => ['nullable', 'array'],
        ];
    }
}
