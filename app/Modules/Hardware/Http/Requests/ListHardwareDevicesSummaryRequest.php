<?php

namespace App\Modules\Hardware\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListHardwareDevicesSummaryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1'],
        ];
    }
}
