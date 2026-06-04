<?php

namespace App\Modules\UserManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignEmployeeUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'userId' => ['required', 'integer', 'min:1'],
        ];
    }
}
