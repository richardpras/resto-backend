<?php

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class PatchOutletReceiptSettingRequest extends FormRequest
{
    private const ALLOWED_KEYS = [
        'receiptHeader',
        'receiptFooter',
        'showLogo',
        'showTaxBreakdown',
    ];

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
            'receiptHeader' => ['required', 'string', 'max:2000'],
            'receiptFooter' => ['required', 'string', 'max:2000'],
            'showLogo' => ['required', 'boolean'],
            'showTaxBreakdown' => ['required', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $body = $this->json()->all();
            $extras = array_diff(array_keys($body), self::ALLOWED_KEYS);
            if ($extras !== []) {
                $validator->errors()->add('_body', 'Unknown fields are not allowed.');
            }
        });
    }
}
