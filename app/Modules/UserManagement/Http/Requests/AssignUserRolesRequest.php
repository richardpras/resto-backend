<?php

namespace App\Modules\UserManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignUserRolesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'roleIds' => ['required', 'array', 'min:1'],
            'roleIds.*' => ['integer', 'exists:roles,id'],
        ];
    }
}
