<?php

namespace App\Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateSupplierPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'paymentDate' => ['sometimes', 'date'],
            'paymentMethod' => ['sometimes', Rule::in(['cash', 'bank_transfer', 'giro', 'check', 'other'])],
            'bankAccountId' => ['sometimes', 'nullable', 'string', 'max:64', 'exists:bank_accounts,id'],
            'referenceNo' => ['sometimes', 'nullable', 'string', 'max:100'],
            'notes' => ['sometimes', 'nullable', 'string'],
            'amount' => ['sometimes', 'numeric', 'gt:0'],
            'allocations' => ['sometimes', 'array', 'min:1'],
            'allocations.*.invoiceId' => ['required_with:allocations', 'integer', 'exists:purchase_invoices,id'],
            'allocations.*.allocatedAmount' => ['required_with:allocations', 'numeric', 'gt:0'],
        ];
    }
}
