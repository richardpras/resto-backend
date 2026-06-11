<?php

namespace App\Modules\Kitchen\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListKitchenTicketsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:queued,in_progress,ready,served,cancelled'],
            'stationId' => ['nullable', 'integer', 'min:1'],
            'stationCode' => ['nullable', 'string', 'max:64'],
            'perPage' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
