<?php

namespace App\Modules\UserManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['nullable', 'integer', 'min:1'],
            'departmentId' => ['nullable', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}
