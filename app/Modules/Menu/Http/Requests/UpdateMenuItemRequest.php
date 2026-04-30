<?php

namespace App\Modules\Menu\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMenuItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'category' => ['sometimes', 'string', 'max:100'],
            'emoji' => ['sometimes', 'string', 'max:10'],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'available' => ['sometimes', 'boolean'],
            'recipes' => ['sometimes', 'array'],
            'recipes.*.ingredientId' => [
                'required_with:recipes',
                'integer',
                Rule::exists('ingredients', 'id')->where(static fn ($query) => $query->where('type', 'ingredient')),
            ],
            'recipes.*.qty' => ['required_with:recipes', 'numeric', 'gt:0'],
        ];
    }
}
