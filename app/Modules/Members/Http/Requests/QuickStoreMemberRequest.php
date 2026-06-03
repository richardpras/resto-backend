<?php

namespace App\Modules\Members\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class QuickStoreMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1'],
            'fullName' => ['required_without:name', 'string', 'max:255'],
            'name' => ['required_without:fullName', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:32'],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'birthDate' => ['nullable', 'date'],
            'birthday' => ['nullable', 'date'],
            'gender' => ['nullable', 'string', 'max:16'],
            'notes' => ['nullable', 'string'],
        ];
    }
}
