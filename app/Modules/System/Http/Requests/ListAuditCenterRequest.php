<?php

namespace App\Modules\System\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class ListAuditCenterRequest extends FormRequest
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
            'outletId' => ['sometimes', 'integer', 'min:1'],
            'module' => ['sometimes', 'string', 'max:64'],
            'userId' => ['sometimes', 'integer', 'min:1'],
            'entityType' => ['sometimes', 'string', 'max:120'],
            'entityId' => ['sometimes', 'integer', 'min:1'],
            'action' => ['sometimes', 'string', 'max:120'],
            'startDate' => ['sometimes', 'date'],
            'endDate' => ['sometimes', 'date'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function validated($key = null, $default = null): array
    {
        return parent::validated();
    }
}
