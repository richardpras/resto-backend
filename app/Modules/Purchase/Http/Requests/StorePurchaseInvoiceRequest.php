<?php

namespace App\Modules\Purchase\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StorePurchaseInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'purchaseOrderId' => ['required', 'integer', 'exists:purchase_orders,id'],
            'goodsReceiptId' => ['required', 'integer', 'exists:goods_receiving_notes,id'],
            'date' => ['required', 'date'],
            'tax' => ['nullable', 'numeric', 'gte:0'],
        ];
    }
}
