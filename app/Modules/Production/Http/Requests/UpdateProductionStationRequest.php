<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateProductionStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:64', 'regex:/^[a-z0-9_]+$/'],
            'name' => ['sometimes', 'string', 'max:120'],
            'type' => ['sometimes', 'string', 'max:64'],
            'displayOrder' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'kdsEnabled' => ['sometimes', 'boolean'],
            'printEnabled' => ['sometimes', 'boolean'],
        ];
    }
}
