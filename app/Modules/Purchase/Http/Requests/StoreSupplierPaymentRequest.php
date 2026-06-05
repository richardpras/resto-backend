<?php

namespace App\Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierPaymentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'supplierId' => ['required', 'integer', 'exists:suppliers,id'],
            'outletId' => ['nullable', 'integer', 'exists:outlets,id'],
            'paymentDate' => ['required', 'date'],
            'paymentMethod' => ['nullable', Rule::in(['cash', 'bank_transfer', 'giro', 'check', 'other'])],
            'referenceNo' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.invoiceId' => ['required_with:allocations', 'integer', 'exists:purchase_invoices,id'],
            'allocations.*.allocatedAmount' => ['required_with:allocations', 'numeric', 'gt:0'],
        ];
    }
}
