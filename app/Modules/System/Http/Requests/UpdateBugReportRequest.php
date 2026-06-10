<?php

namespace App\Modules\System\Http\Requests;

use App\Models\Modules\System\Domain\BugReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateBugReportRequest extends FormRequest
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
            'status' => ['sometimes', 'string', Rule::in(BugReport::STATUSES)],
            'severity' => ['sometimes', 'string', Rule::in(BugReport::SEVERITIES)],
            'assignedToUserId' => ['sometimes', 'nullable', 'integer', 'min:1', 'exists:users,id'],
        ];
    }
}
