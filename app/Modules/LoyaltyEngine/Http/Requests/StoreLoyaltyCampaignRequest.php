<?php

namespace App\Modules\LoyaltyEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoyaltyCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:64'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'segmentId' => ['required', 'integer', 'min:1'],
            'campaignType' => ['nullable', 'string', 'max:64'],
            'scheduledAt' => ['nullable', 'date'],
        ];
    }
}
