<?php

namespace App\Modules\Print\Http\Requests;

use App\Modules\Print\Support\ReceiptDocumentKind;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RenderReceiptDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'outletId' => ['required', 'integer', 'min:1', 'exists:outlets,id'],
            'kind' => ['required', 'string', Rule::in(ReceiptDocumentKind::values())],
            'sourceType' => ['required', 'string', Rule::in(['order', 'pos_session', 'payment_transaction'])],
            'sourceId' => ['required', 'integer', 'min:1'],
            'orderSplitId' => ['nullable', 'integer', 'exists:order_splits,id'],
            'issueFiscal' => ['sometimes', 'boolean'],
            'generatePdf' => ['sometimes', 'boolean'],
            'queuePrint' => ['sometimes', 'boolean'],
            'forceRegenerate' => ['sometimes', 'boolean'],
        ];
    }
}
