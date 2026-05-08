<?php

namespace App\Modules\Monitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetMonitoringMetricsRequest extends FormRequest
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
            'outletId' => ['nullable', 'integer', 'min:1', 'exists:outlets,id'],
            'dateFrom' => ['nullable', 'date'],
            'dateTo' => ['nullable', 'date', 'after_or_equal:dateFrom'],
        ];
    }
}
