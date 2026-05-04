<?php

namespace App\Modules\Settings\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateSettingsRequest extends FormRequest
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
            'merchant' => ['required', 'array'],
            'merchant.name' => ['required', 'string', 'max:255'],
            'merchant.businessType' => ['required', 'string', 'max:100'],
            'merchant.address' => ['required', 'string', 'max:2000'],
            'merchant.phone' => ['required', 'string', 'max:64'],
            'merchant.email' => ['required', 'email', 'max:255'],
            'merchant.currency' => ['required', 'string', 'max:8'],
            'merchant.timezone' => ['required', 'string', 'max:64'],
            'merchant.language' => ['required', 'string', 'max:16'],
            'merchant.logo' => ['nullable', 'string', 'max:2048'],

            'outlets' => ['required', 'array'],
            'outlets.*' => ['required', 'array'],
            'outlets.*.id' => ['required', 'string', 'max:64'],
            'outlets.*.name' => ['required', 'string', 'max:255'],
            'outlets.*.address' => ['required', 'string', 'max:2000'],
            'outlets.*.phone' => ['required', 'string', 'max:64'],
            'outlets.*.manager' => ['required', 'string', 'max:255'],
            'outlets.*.status' => ['required', 'string', 'in:active,inactive'],
            'outlets.*.logo' => ['nullable', 'string', 'max:2048'],
            'outlets.*.receiptHeader' => ['nullable', 'string', 'max:2000'],
            'outlets.*.receiptFooter' => ['nullable', 'string', 'max:2000'],
            'outlets.*.showLogo' => ['nullable', 'boolean'],
            'outlets.*.showTaxBreakdown' => ['nullable', 'boolean'],
            'outlets.*.invoicePrefix' => ['nullable', 'string', 'max:64'],
            'outlets.*.orderPrefix' => ['nullable', 'string', 'max:64'],

            'taxes' => ['required', 'array'],
            'taxes.*' => ['required', 'array'],
            'taxes.*.id' => ['required', 'string', 'max:64'],
            'taxes.*.name' => ['required', 'string', 'max:255'],
            'taxes.*.type' => ['required', 'string', 'in:percentage,fixed'],
            'taxes.*.value' => ['required', 'numeric'],
            'taxes.*.applyDineIn' => ['required', 'boolean'],
            'taxes.*.applyTakeaway' => ['required', 'boolean'],
            'taxes.*.inclusive' => ['required', 'boolean'],
            'taxes.*.status' => ['required', 'string', 'in:active,inactive'],

            'printers' => ['required', 'array'],
            'printers.*' => ['required', 'array'],
            'printers.*.id' => ['required', 'string', 'max:64'],
            'printers.*.name' => ['required', 'string', 'max:255'],
            'printers.*.printerType' => ['required', 'string', 'in:kitchen,cashier'],
            'printers.*.connection' => ['required', 'string', 'in:bluetooth,lan'],
            'printers.*.ip' => ['nullable', 'string', 'max:64'],
            'printers.*.bluetoothDevice' => ['nullable', 'string', 'max:255'],
            'printers.*.outletId' => ['required', 'string', 'max:64'],
            'printers.*.assignedCategories' => ['nullable', 'array'],
            'printers.*.assignedCategories.*' => ['string', 'max:255'],

            'paymentMethods' => ['required', 'array'],
            'paymentMethods.*' => ['required', 'array'],
            'paymentMethods.*.id' => ['required', 'string', 'max:64'],
            'paymentMethods.*.name' => ['required', 'string', 'max:255'],
            'paymentMethods.*.type' => ['required', 'string', 'in:cash,digital'],
            'paymentMethods.*.integration' => ['nullable', 'string', 'max:255'],
            'paymentMethods.*.fee' => ['nullable', 'numeric'],
            'paymentMethods.*.status' => ['required', 'string', 'in:active,inactive'],

            'system' => ['required', 'array'],
            'system.enableSplitBill' => ['required', 'boolean'],
            'system.enableMultiPayment' => ['required', 'boolean'],
            'system.confirmBeforePayment' => ['required', 'boolean'],
            'system.enableQROrdering' => ['required', 'boolean'],

            'integration' => ['required', 'array'],
            'integration.paymentGatewayKey' => ['nullable', 'string', 'max:1024'],
            'integration.webhookUrl' => ['nullable', 'string', 'max:2048'],
            'integration.printAgentUrl' => ['nullable', 'string', 'max:2048'],
            'integration.thirdPartyNotes' => ['nullable', 'string', 'max:8000'],

            'numbering' => ['required', 'array'],
            'numbering.invoiceFormat' => ['required', 'string', 'max:128'],
            'numbering.orderFormat' => ['required', 'string', 'max:128'],

            'banks' => ['required', 'array'],
            'banks.*' => ['required', 'array'],
            'banks.*.id' => ['required', 'string', 'max:64'],
            'banks.*.bankName' => ['required', 'string', 'max:255'],
            'banks.*.accountName' => ['required', 'string', 'max:255'],
            'banks.*.accountNumber' => ['required', 'string', 'max:64'],
            'banks.*.isDefault' => ['required', 'boolean'],
        ];
    }
}
