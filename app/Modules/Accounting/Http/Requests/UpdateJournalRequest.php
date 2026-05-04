<?php

namespace App\Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateJournalRequest extends FormRequest
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
            'journalDate' => ['sometimes', 'date_format:Y-m-d'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'outlet' => ['sometimes', 'nullable', 'string', 'max:255'],
            'lines' => ['sometimes', 'array', 'min:2'],
            'lines.*.accountId' => ['required_with:lines', 'integer', 'exists:accounts,id'],
            'lines.*.debit' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.credit' => ['required_with:lines', 'numeric', 'min:0'],
            'lines.*.memo' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
