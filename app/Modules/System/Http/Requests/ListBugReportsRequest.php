<?php

namespace App\Modules\System\Http\Requests;

use App\Models\Modules\System\Domain\BugReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListBugReportsRequest extends FormRequest
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
            'outletId' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', Rule::in(BugReport::STATUSES)],
            'severity' => ['nullable', 'string', Rule::in(BugReport::SEVERITIES)],
            'search' => ['nullable', 'string', 'max:200'],
            'page' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
