<?php

namespace App\Modules\Production\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListProductionStationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1'],
            'activeOnly' => ['sometimes', 'boolean'],
        ];
    }
}
