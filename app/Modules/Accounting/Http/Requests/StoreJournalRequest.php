<?php

namespace App\Modules\Accounting\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreJournalRequest extends FormRequest
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
            'tenantId' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'journalNo' => ['sometimes', 'nullable', 'string', 'max:64', 'unique:journals,journal_no'],
            'journalDate' => ['required', 'date_format:Y-m-d'],
            'description' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'outlet' => ['sometimes', 'nullable', 'string', 'max:255'],
            'outletId' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'in:draft,posted'],
            'postingKey' => ['sometimes', 'nullable', 'string', 'max:120'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.accountId' => ['required', 'integer', 'exists:accounts,id'],
            'lines.*.debit' => ['required', 'numeric', 'min:0'],
            'lines.*.credit' => ['required', 'numeric', 'min:0'],
            'lines.*.memo' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }
}
