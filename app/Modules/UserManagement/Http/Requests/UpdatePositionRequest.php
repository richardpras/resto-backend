<?php

namespace App\Modules\UserManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePositionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'departmentId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'code' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'sortOrder' => ['sometimes', 'integer', 'min:0'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}
