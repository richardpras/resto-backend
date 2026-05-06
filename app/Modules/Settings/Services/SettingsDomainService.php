<?php

namespace App\Modules\Settings\Services;

use App\Models\Modules\Settings\Domain\BankAccount;
use App\Models\Modules\Settings\Domain\IntegrationSetting;
use App\Models\Modules\Settings\Domain\MerchantSetting;
use App\Models\Modules\Settings\Domain\NumberingSetting;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\PaymentMethod;
use App\Models\Modules\Settings\Domain\SettingPrinter;
use App\Models\Modules\Settings\Domain\SystemSetting;
use App\Models\Modules\Settings\Domain\Tax;
use App\Modules\Settings\Support\TemplateSettingsPayload;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class SettingsDomainService
{
    private const SINGLETON_ID = 1;

    /** @return array<string, mixed> */
    public function getMerchant(): array
    {
        $row = MerchantSetting::query()->first();
        if ($row === null) {
            $m = TemplateSettingsPayload::load()['merchant'] ?? [];

            return $this->mapMerchantFromTemplate($m);
        }

        return $this->merchantToCamel($row);
    }

    /** @param  array<string, mixed>  $data */
    public function putMerchant(array $data): array
    {
        $row = MerchantSetting::query()->updateOrCreate(
            ['id' => self::SINGLETON_ID],
            [
                'name' => $data['name'],
                'business_type' => $data['businessType'],
                'address' => $data['address'],
                'phone' => $data['phone'],
                'email' => $data['email'],
                'currency' => $data['currency'],
                'timezone' => $data['timezone'],
                'language' => $data['language'],
                'logo' => $data['logo'] ?? null,
            ],
        );

        return $this->merchantToCamel($row);
    }

    /** @return list<array<string, mixed>> */
    public function listOutlets(): array
    {
        return Outlet::query()->orderBy('name')->get()->map(fn (Outlet $o) => $this->outletToCamel($o))->all();
    }

    /** @param  array<string, mixed>  $data */
    public function createOutlet(array $data): array
    {
        $codeInput = isset($data['code']) ? trim((string) $data['code']) : '';

        $placeholder = 'tmp-'.bin2hex(random_bytes(8));

        /** @var Outlet $o */
        $o = Outlet::query()->create([
            'code' => $placeholder,
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'manager' => $data['manager'] ?? null,
            'status' => $data['status'] ?? 'active',
            'logo' => $data['logo'] ?? null,
            'invoice_prefix' => $data['invoicePrefix'] ?? null,
            'order_prefix' => $data['orderPrefix'] ?? null,
        ]);

        $finalCode = $codeInput !== '' ? $codeInput : 'OUT-'.$o->id;
        $o->update(['code' => $finalCode]);

        return $this->outletToCamel($o->fresh());
    }

    /** @param  array<string, mixed>  $data */
    public function updateOutlet(int $id, array $data): array
    {
        $o = Outlet::query()->whereKey($id)->first();
        if ($o === null) {
            throw (new ModelNotFoundException)->setModel(Outlet::class, [(string) $id]);
        }

        $payload = [
            'name' => $data['name'],
            'address' => $data['address'] ?? null,
            'phone' => $data['phone'] ?? null,
            'manager' => $data['manager'] ?? null,
            'status' => $data['status'] ?? $o->status,
            'logo' => $data['logo'] ?? null,
            'invoice_prefix' => $data['invoicePrefix'] ?? null,
            'order_prefix' => $data['orderPrefix'] ?? null,
        ];

        if (array_key_exists('code', $data) && $data['code'] !== null) {
            $c = trim((string) $data['code']);
            if ($c !== '') {
                $payload['code'] = $c;
            }
        }

        $o->fill($payload);
        $o->save();

        return $this->outletToCamel($o->fresh());
    }

    public function deleteOutlet(int $id): void
    {
        $o = Outlet::query()->whereKey($id)->first();
        if ($o === null) {
            throw (new ModelNotFoundException)->setModel(Outlet::class, [(string) $id]);
        }
        $o->delete();
    }

    /** @return list<array<string, mixed>> */
    public function listTaxes(): array
    {
        return Tax::query()->orderBy('name')->get()->map(fn (Tax $t) => $this->taxToCamel($t))->all();
    }

    /** @param  array<string, mixed>  $data */
    public function createTax(array $data): array
    {
        $t = Tax::query()->create([
            'id' => $data['id'],
            'name' => $data['name'],
            'type' => $data['type'],
            'value' => $data['value'],
            'apply_dine_in' => $data['applyDineIn'],
            'apply_takeaway' => $data['applyTakeaway'],
            'inclusive' => $data['inclusive'],
            'status' => $data['status'],
        ]);

        return $this->taxToCamel($t);
    }

    /** @param  array<string, mixed>  $data */
    public function updateTax(string $id, array $data): array
    {
        $t = Tax::query()->whereKey($id)->first();
        if ($t === null) {
            throw (new ModelNotFoundException)->setModel(Tax::class, [$id]);
        }
        $t->fill([
            'name' => $data['name'],
            'type' => $data['type'],
            'value' => $data['value'],
            'apply_dine_in' => $data['applyDineIn'],
            'apply_takeaway' => $data['applyTakeaway'],
            'inclusive' => $data['inclusive'],
            'status' => $data['status'],
        ]);
        $t->save();

        return $this->taxToCamel($t->fresh());
    }

    public function deleteTax(string $id): void
    {
        $t = Tax::query()->whereKey($id)->first();
        if ($t === null) {
            throw (new ModelNotFoundException)->setModel(Tax::class, [$id]);
        }
        $t->delete();
    }

    /** @return list<array<string, mixed>> */
    public function listPrinters(): array
    {
        return SettingPrinter::query()->orderBy('name')->get()->map(fn (SettingPrinter $p) => $this->printerToCamel($p))->all();
    }

    /** @param  array<string, mixed>  $data */
    public function createPrinter(array $data): array
    {
        $p = SettingPrinter::query()->create([
            'id' => $data['id'],
            'name' => $data['name'],
            'printer_type' => $data['printerType'],
            'connection' => $data['connection'],
            'ip' => $data['ip'] ?? null,
            'bluetooth_device' => $data['bluetoothDevice'] ?? null,
            'outlet_id' => (int) $data['outletId'],
            'assigned_categories' => $data['assignedCategories'] ?? null,
        ]);

        return $this->printerToCamel($p);
    }

    /** @param  array<string, mixed>  $data */
    public function updatePrinter(string $id, array $data): array
    {
        $p = SettingPrinter::query()->whereKey($id)->first();
        if ($p === null) {
            throw (new ModelNotFoundException)->setModel(SettingPrinter::class, [$id]);
        }
        $p->fill([
            'name' => $data['name'],
            'printer_type' => $data['printerType'],
            'connection' => $data['connection'],
            'ip' => $data['ip'] ?? null,
            'bluetooth_device' => $data['bluetoothDevice'] ?? null,
            'outlet_id' => (int) $data['outletId'],
            'assigned_categories' => $data['assignedCategories'] ?? null,
        ]);
        $p->save();

        return $this->printerToCamel($p->fresh());
    }

    public function deletePrinter(string $id): void
    {
        $p = SettingPrinter::query()->whereKey($id)->first();
        if ($p === null) {
            throw (new ModelNotFoundException)->setModel(SettingPrinter::class, [$id]);
        }
        $p->delete();
    }

    /** @return list<array<string, mixed>> */
    public function listPaymentMethods(): array
    {
        return PaymentMethod::query()->orderBy('name')->get()->map(fn (PaymentMethod $p) => $this->paymentMethodToCamel($p))->all();
    }

    /** @param  array<string, mixed>  $data */
    public function createPaymentMethod(array $data): array
    {
        $p = PaymentMethod::query()->create([
            'id' => $data['id'],
            'name' => $data['name'],
            'type' => $data['type'],
            'integration' => $data['integration'] ?? null,
            'fee' => $data['fee'] ?? null,
            'status' => $data['status'],
        ]);

        return $this->paymentMethodToCamel($p);
    }

    /** @param  array<string, mixed>  $data */
    public function updatePaymentMethod(string $id, array $data): array
    {
        $p = PaymentMethod::query()->whereKey($id)->first();
        if ($p === null) {
            throw (new ModelNotFoundException)->setModel(PaymentMethod::class, [$id]);
        }
        $p->fill([
            'name' => $data['name'],
            'type' => $data['type'],
            'integration' => $data['integration'] ?? null,
            'fee' => $data['fee'] ?? null,
            'status' => $data['status'],
        ]);
        $p->save();

        return $this->paymentMethodToCamel($p->fresh());
    }

    public function deletePaymentMethod(string $id): void
    {
        $p = PaymentMethod::query()->whereKey($id)->first();
        if ($p === null) {
            throw (new ModelNotFoundException)->setModel(PaymentMethod::class, [$id]);
        }
        $p->delete();
    }

    /** @return list<array<string, mixed>> */
    public function listBankAccounts(): array
    {
        return BankAccount::query()->orderBy('bank_name')->get()->map(fn (BankAccount $b) => $this->bankToCamel($b))->all();
    }

    /** @param  array<string, mixed>  $data */
    public function createBankAccount(array $data): array
    {
        $b = BankAccount::query()->create([
            'id' => $data['id'],
            'bank_name' => $data['bankName'],
            'account_name' => $data['accountName'],
            'account_number' => $data['accountNumber'],
            'is_default' => $data['isDefault'],
        ]);

        return $this->bankToCamel($b);
    }

    /** @param  array<string, mixed>  $data */
    public function updateBankAccount(string $id, array $data): array
    {
        $b = BankAccount::query()->whereKey($id)->first();
        if ($b === null) {
            throw (new ModelNotFoundException)->setModel(BankAccount::class, [$id]);
        }
        $b->fill([
            'bank_name' => $data['bankName'],
            'account_name' => $data['accountName'],
            'account_number' => $data['accountNumber'],
            'is_default' => $data['isDefault'],
        ]);
        $b->save();

        return $this->bankToCamel($b->fresh());
    }

    public function deleteBankAccount(string $id): void
    {
        $b = BankAccount::query()->whereKey($id)->first();
        if ($b === null) {
            throw (new ModelNotFoundException)->setModel(BankAccount::class, [$id]);
        }
        $b->delete();
    }

    /** @return array<string, mixed> */
    public function getSystem(): array
    {
        $row = SystemSetting::query()->first();
        if ($row === null) {
            $s = TemplateSettingsPayload::load()['system'] ?? [];

            return [
                'enableSplitBill' => (bool) ($s['enableSplitBill'] ?? true),
                'enableMultiPayment' => (bool) ($s['enableMultiPayment'] ?? true),
                'confirmBeforePayment' => (bool) ($s['confirmBeforePayment'] ?? true),
                'enableQROrdering' => (bool) ($s['enableQROrdering'] ?? true),
            ];
        }

        return [
            'enableSplitBill' => $row->enable_split_bill,
            'enableMultiPayment' => $row->enable_multi_payment,
            'confirmBeforePayment' => $row->confirm_before_payment,
            'enableQROrdering' => $row->enable_qr_ordering,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function putSystem(array $data): array
    {
        $row = SystemSetting::query()->updateOrCreate(
            ['id' => self::SINGLETON_ID],
            [
                'enable_split_bill' => $data['enableSplitBill'],
                'enable_multi_payment' => $data['enableMultiPayment'],
                'confirm_before_payment' => $data['confirmBeforePayment'],
                'enable_qr_ordering' => $data['enableQROrdering'],
            ],
        );

        return [
            'enableSplitBill' => $row->enable_split_bill,
            'enableMultiPayment' => $row->enable_multi_payment,
            'confirmBeforePayment' => $row->confirm_before_payment,
            'enableQROrdering' => $row->enable_qr_ordering,
        ];
    }

    /** @return array<string, mixed> */
    public function getIntegration(): array
    {
        $row = IntegrationSetting::query()->first();
        if ($row === null) {
            $i = TemplateSettingsPayload::load()['integration'] ?? [];

            return [
                'paymentGatewayKey' => (string) ($i['paymentGatewayKey'] ?? ''),
                'webhookUrl' => (string) ($i['webhookUrl'] ?? ''),
                'printAgentUrl' => (string) ($i['printAgentUrl'] ?? ''),
                'thirdPartyNotes' => (string) ($i['thirdPartyNotes'] ?? ''),
            ];
        }

        return [
            'paymentGatewayKey' => (string) ($row->payment_gateway_key ?? ''),
            'webhookUrl' => (string) ($row->webhook_url ?? ''),
            'printAgentUrl' => (string) ($row->print_agent_url ?? ''),
            'thirdPartyNotes' => (string) ($row->third_party_notes ?? ''),
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function putIntegration(array $data): array
    {
        $row = IntegrationSetting::query()->updateOrCreate(
            ['id' => self::SINGLETON_ID],
            [
                'payment_gateway_key' => $data['paymentGatewayKey'],
                'webhook_url' => $data['webhookUrl'],
                'print_agent_url' => $data['printAgentUrl'],
                'third_party_notes' => $data['thirdPartyNotes'],
            ],
        );

        return [
            'paymentGatewayKey' => (string) ($row->payment_gateway_key ?? ''),
            'webhookUrl' => (string) ($row->webhook_url ?? ''),
            'printAgentUrl' => (string) ($row->print_agent_url ?? ''),
            'thirdPartyNotes' => (string) ($row->third_party_notes ?? ''),
        ];
    }

    /** @return array<string, mixed> */
    public function getNumbering(): array
    {
        $row = NumberingSetting::query()->first();
        if ($row === null) {
            $n = TemplateSettingsPayload::load()['numbering'] ?? [];

            return [
                'invoiceFormat' => (string) ($n['invoiceFormat'] ?? ''),
                'orderFormat' => (string) ($n['orderFormat'] ?? ''),
            ];
        }

        return [
            'invoiceFormat' => $row->invoice_format,
            'orderFormat' => $row->order_format,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public function putNumbering(array $data): array
    {
        $row = NumberingSetting::query()->updateOrCreate(
            ['id' => self::SINGLETON_ID],
            [
                'invoice_format' => $data['invoiceFormat'],
                'order_format' => $data['orderFormat'],
            ],
        );

        return [
            'invoiceFormat' => $row->invoice_format,
            'orderFormat' => $row->order_format,
        ];
    }

    /** @param  array<string, mixed>  $m */
    private function mapMerchantFromTemplate(array $m): array
    {
        $out = [
            'name' => (string) ($m['name'] ?? ''),
            'businessType' => (string) ($m['businessType'] ?? ''),
            'address' => (string) ($m['address'] ?? ''),
            'phone' => (string) ($m['phone'] ?? ''),
            'email' => (string) ($m['email'] ?? ''),
            'currency' => (string) ($m['currency'] ?? 'IDR'),
            'timezone' => (string) ($m['timezone'] ?? 'Asia/Jakarta'),
            'language' => (string) ($m['language'] ?? 'en'),
        ];
        if (isset($m['logo']) && $m['logo'] !== '') {
            $out['logo'] = (string) $m['logo'];
        }

        return $out;
    }

    /** @return array{name: string, businessType: string, address: string, phone: string, email: string, currency: string, timezone: string, language: string, logo?: string} */
    private function merchantToCamel(MerchantSetting $row): array
    {
        $out = [
            'name' => $row->name,
            'businessType' => $row->business_type,
            'address' => $row->address,
            'phone' => $row->phone,
            'email' => $row->email,
            'currency' => $row->currency,
            'timezone' => $row->timezone,
            'language' => $row->language,
        ];
        if ($row->logo !== null && $row->logo !== '') {
            $out['logo'] = $row->logo;
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function outletToCamel(Outlet $o): array
    {
        $base = [
            'id' => (int) $o->id,
            'code' => (string) ($o->code ?? ''),
            'name' => $o->name,
            'address' => (string) ($o->address ?? ''),
            'phone' => (string) ($o->phone ?? ''),
            'manager' => (string) ($o->manager ?? ''),
            'status' => $o->status ?? 'active',
            'invoicePrefix' => $o->invoice_prefix,
            'orderPrefix' => $o->order_prefix,
        ];
        if ($o->logo !== null && $o->logo !== '') {
            $base['logo'] = $o->logo;
        }

        return $base;
    }

    /** @return array<string, mixed> */
    private function taxToCamel(Tax $t): array
    {
        return [
            'id' => $t->id,
            'name' => $t->name,
            'type' => $t->type,
            'value' => (float) $t->value,
            'applyDineIn' => $t->apply_dine_in,
            'applyTakeaway' => $t->apply_takeaway,
            'inclusive' => $t->inclusive,
            'status' => $t->status,
        ];
    }

    /** @return array<string, mixed> */
    private function printerToCamel(SettingPrinter $p): array
    {
        $base = [
            'id' => $p->id,
            'name' => $p->name,
            'printerType' => $p->printer_type,
            'connection' => $p->connection,
            'outletId' => (int) $p->outlet_id,
        ];
        if ($p->ip !== null && $p->ip !== '') {
            $base['ip'] = $p->ip;
        }
        if ($p->bluetooth_device !== null && $p->bluetooth_device !== '') {
            $base['bluetoothDevice'] = $p->bluetooth_device;
        }
        $cats = $p->assigned_categories;
        if (is_array($cats) && $cats !== []) {
            $base['assignedCategories'] = $cats;
        }

        return $base;
    }

    /** @return array<string, mixed> */
    private function paymentMethodToCamel(PaymentMethod $p): array
    {
        $base = [
            'id' => $p->id,
            'name' => $p->name,
            'type' => $p->type,
            'status' => $p->status,
        ];
        if ($p->integration !== null && $p->integration !== '') {
            $base['integration'] = $p->integration;
        }
        if ($p->fee !== null) {
            $base['fee'] = (float) $p->fee;
        }

        return $base;
    }

    /** @return array<string, mixed> */
    private function bankToCamel(BankAccount $b): array
    {
        return [
            'id' => $b->id,
            'bankName' => $b->bank_name,
            'accountName' => $b->account_name,
            'accountNumber' => $b->account_number,
            'isDefault' => $b->is_default,
        ];
    }
}
