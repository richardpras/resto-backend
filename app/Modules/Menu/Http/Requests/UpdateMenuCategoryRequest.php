<?php

namespace App\Modules\Menu\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMenuCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'nameEn' => ['nullable', 'string', 'max:120'],
            'nameId' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'descriptionEn' => ['nullable', 'string', 'max:255'],
            'descriptionId' => ['nullable', 'string', 'max:255'],
            'sortOrder' => ['sometimes', 'integer', 'min:0', 'max:9999'],
            'isActive' => ['sometimes', 'boolean'],
        ];
    }
}
