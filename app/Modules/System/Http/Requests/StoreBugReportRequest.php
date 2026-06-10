<?php

namespace App\Modules\System\Http\Requests;

use App\Models\Modules\System\Domain\BugReport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreBugReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null || $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'outletId' => ['nullable', 'integer', 'min:1'],
            'title' => ['required', 'string', 'max:200'],
            'message' => ['required', 'string', 'max:5000'],
            'severity' => ['nullable', 'string', Rule::in(BugReport::SEVERITIES)],
            'currentRoute' => ['nullable', 'string', 'max:500'],
            'browser' => ['nullable', 'string', 'max:200'],
            'userAgent' => ['nullable', 'string', 'max:2000'],
            'viewport' => ['nullable', 'string', 'max:50'],
            'appVersion' => ['nullable', 'string', 'max:50'],
            'diagnosticsJson' => ['nullable'],
            'screenshot' => ['nullable', 'file', 'mimes:png,jpg,jpeg,webp', 'max:5120'],
        ];
    }
}
