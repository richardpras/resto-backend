<?php

namespace App\Modules\System\Http\Requests;

use App\Modules\System\Services\FailedJobSeverityEngine;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ListFailedJobsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('api') !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'module' => ['sometimes', 'string', 'max:40'],
            'severity' => ['sometimes', 'string', Rule::in([
                FailedJobSeverityEngine::JOB_TIER_CRITICAL,
                FailedJobSeverityEngine::JOB_TIER_WARNING,
                FailedJobSeverityEngine::JOB_TIER_INFO,
            ])],
            'queue' => ['sometimes', 'string', 'max:120'],
            'dateFrom' => ['sometimes', 'date'],
            'dateTo' => ['sometimes', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
