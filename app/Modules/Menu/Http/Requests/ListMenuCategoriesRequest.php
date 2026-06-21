<?php

namespace App\Modules\Menu\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListMenuCategoriesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenantId' => ['nullable', 'integer', 'min:1'],
            'activeOnly' => ['nullable', 'boolean'],
        ];
    }
}
