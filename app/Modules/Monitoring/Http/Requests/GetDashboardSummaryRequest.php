<?php

namespace App\Modules\Monitoring\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GetDashboardSummaryRequest extends FormRequest
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
            'startDate' => ['nullable', 'date'],
            'endDate' => ['nullable', 'date', 'after_or_equal:startDate'],
            'outletId' => ['nullable', 'integer', 'min:1', 'exists:outlets,id'],
        ];
    }
}

