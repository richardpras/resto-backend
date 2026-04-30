<?php

namespace App\Modules\UserManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'permissionIds' => ['required', 'array', 'min:1'],
            'permissionIds.*' => ['integer', 'exists:permissions,id'],
        ];
    }
}
