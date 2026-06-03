<?php

namespace App\Modules\Members\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMemberRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'fullName' => ['sometimes', 'string', 'max:255'],
            'name' => ['sometimes', 'string', 'max:255'],
            'phone' => ['sometimes', 'string', 'max:32'],
            'email' => ['sometimes', 'nullable', 'string', 'email', 'max:255'],
            'birthDate' => ['sometimes', 'nullable', 'date'],
            'birthday' => ['sometimes', 'nullable', 'date'],
            'gender' => ['sometimes', 'nullable', 'string', 'max:16'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'isActive' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,inactive'],
        ];
    }
}
