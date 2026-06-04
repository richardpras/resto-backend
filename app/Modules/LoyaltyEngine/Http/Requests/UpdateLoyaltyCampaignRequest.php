<?php

namespace App\Modules\LoyaltyEngine\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLoyaltyCampaignRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'string', 'max:64'],
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'segmentId' => ['sometimes', 'integer', 'min:1'],
            'campaignType' => ['sometimes', 'string', 'max:64'],
            'scheduledAt' => ['sometimes', 'nullable', 'date'],
        ];
    }
}
