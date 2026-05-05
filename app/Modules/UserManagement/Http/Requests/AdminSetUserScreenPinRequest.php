<?php

namespace App\Modules\UserManagement\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Admin resets another user's POS screen-unlock PIN (no current PIN required). */
class AdminSetUserScreenPinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'pin' => ['required', 'string', 'size:4', 'regex:/^[0-9]{4}$/'],
        ];
    }
}
