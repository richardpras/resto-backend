<?php

namespace App\Modules\Orders\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ShiftClosePostingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tenantId' => ['nullable', 'integer', 'min:1'],
            'outletId' => ['nullable', 'integer', 'min:1'],
            'cashAccountCode' => ['nullable', 'string', 'max:50'],
            'revenueAccountCode' => ['nullable', 'string', 'max:50'],
            'cogsAccountCode' => ['nullable', 'string', 'max:50'],
            'inventoryAccountCode' => ['nullable', 'string', 'max:50'],
        ];
    }
}
