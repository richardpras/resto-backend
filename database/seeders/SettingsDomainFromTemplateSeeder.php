<?php

namespace Database\Seeders;

use App\Models\Modules\Settings\Domain\BankAccount;
use App\Models\Modules\Settings\Domain\IntegrationSetting;
use App\Models\Modules\Settings\Domain\MerchantSetting;
use App\Models\Modules\Settings\Domain\NumberingSetting;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletReceiptSetting;
use App\Models\Modules\Settings\Domain\PaymentMethod;
use App\Models\Modules\Settings\Domain\SettingPrinter;
use App\Models\Modules\Settings\Domain\SystemSetting;
use App\Models\Modules\Settings\Domain\Tax;
use App\Modules\Settings\Support\TemplateSettingsPayload;
use Illuminate\Database\Seeder;

/**
 * Idempotent: syncs relational settings tables from database/data/default_app_settings.json.
 */
class SettingsDomainFromTemplateSeeder extends Seeder
{
    private const SINGLETON_ID = 1;

    public function run(): void
    {
        $payload = TemplateSettingsPayload::load();

        $this->seedMerchant($payload['merchant'] ?? []);
        $this->seedOutletsAndReceipts($payload['outlets'] ?? []);
        $this->seedTaxes($payload['taxes'] ?? []);
        $this->seedPrinters($payload['printers'] ?? []);
        $this->seedPaymentMethods($payload['paymentMethods'] ?? []);
        $this->seedBankAccounts($payload['banks'] ?? []);
        $this->seedSystem($payload['system'] ?? []);
        $this->seedIntegration($payload['integration'] ?? []);
        $this->seedNumbering($payload['numbering'] ?? []);
    }

    /** @param  array<string, mixed>  $m */
    private function seedMerchant(array $m): void
    {
        if ($m === []) {
            return;
        }

        MerchantSetting::query()->updateOrCreate(
            ['id' => self::SINGLETON_ID],
            [
                'name' => (string) ($m['name'] ?? ''),
                'business_type' => (string) ($m['businessType'] ?? ''),
                'address' => (string) ($m['address'] ?? ''),
                'phone' => (string) ($m['phone'] ?? ''),
                'email' => (string) ($m['email'] ?? ''),
                'currency' => (string) ($m['currency'] ?? ''),
                'timezone' => (string) ($m['timezone'] ?? ''),
                'language' => (string) ($m['language'] ?? ''),
                'logo' => $m['logo'] ?? null,
            ],
        );
    }

    /** @param  list<mixed>  $outlets */
    private function seedOutletsAndReceipts(array $outlets): void
    {
        foreach ($outlets as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rawId = $row['id'] ?? null;
            $id = is_int($rawId)
                ? $rawId
                : (is_string($rawId) && ctype_digit($rawId) ? (int) $rawId : null);
            $name = $row['name'] ?? null;
            if ($id === null || $id < 1 || ! is_string($name) || $name === '') {
                continue;
            }

            $codeRaw = $row['code'] ?? null;
            $code = is_string($codeRaw) && trim($codeRaw) !== '' ? trim($codeRaw) : 'OUT-'.$id;

            $outlet = Outlet::query()->updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'address' => isset($row['address']) ? (string) $row['address'] : null,
                    'phone' => isset($row['phone']) ? (string) $row['phone'] : null,
                    'manager' => isset($row['manager']) ? (string) $row['manager'] : null,
                    'status' => isset($row['status']) && is_string($row['status']) ? $row['status'] : 'active',
                    'logo' => isset($row['logo']) ? (string) $row['logo'] : null,
                    'invoice_prefix' => isset($row['invoicePrefix']) ? (string) $row['invoicePrefix'] : null,
                    'order_prefix' => isset($row['orderPrefix']) ? (string) $row['orderPrefix'] : null,
                ],
            );

            $header = $row['receiptHeader'] ?? null;
            $footer = $row['receiptFooter'] ?? null;
            OutletReceiptSetting::query()->updateOrCreate(
                ['outlet_id' => $outlet->id],
                [
                    'receipt_header' => is_string($header) ? $header : null,
                    'receipt_footer' => is_string($footer) ? $footer : null,
                    'show_logo' => filter_var($row['showLogo'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'show_tax_breakdown' => filter_var($row['showTaxBreakdown'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ],
            );
        }
    }

    private function resolveTemplateOutletId(?int $templateOutletId): ?int
    {
        if ($templateOutletId === null || $templateOutletId < 1) {
            return null;
        }

        $codeByTemplateId = [1 => 'o-main', 2 => 'o-branch'];
        $code = $codeByTemplateId[$templateOutletId] ?? null;
        if ($code !== null) {
            $resolved = Outlet::query()->where('code', $code)->value('id');

            return $resolved !== null ? (int) $resolved : null;
        }

        return Outlet::query()->orderBy('id')->skip($templateOutletId - 1)->value('id');
    }

    /** @param  list<mixed>  $rows */
    private function seedTaxes(array $rows): void
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $row['id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            Tax::query()->updateOrCreate(
                ['id' => $id],
                [
                    'name' => (string) ($row['name'] ?? ''),
                    'type' => (string) ($row['type'] ?? 'percentage'),
                    'value' => $row['value'] ?? 0,
                    'apply_dine_in' => filter_var($row['applyDineIn'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'apply_takeaway' => filter_var($row['applyTakeaway'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'inclusive' => filter_var($row['inclusive'] ?? false, FILTER_VALIDATE_BOOLEAN),
                    'status' => (string) ($row['status'] ?? 'active'),
                ],
            );
        }
    }

    /** @param  list<mixed>  $rows */
    private function seedPrinters(array $rows): void
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $row['id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            $rawOutletId = $row['outletId'] ?? null;
            $outletIdInt = is_int($rawOutletId)
                ? $rawOutletId
                : (is_string($rawOutletId) && ctype_digit($rawOutletId) ? (int) $rawOutletId : null);
            $resolvedOutletId = $this->resolveTemplateOutletId($outletIdInt);
            if ($resolvedOutletId === null) {
                continue;
            }
            SettingPrinter::query()->updateOrCreate(
                ['id' => $id],
                [
                    'name' => (string) ($row['name'] ?? ''),
                    'printer_type' => (string) ($row['printerType'] ?? 'kitchen'),
                    'connection' => (string) ($row['connection'] ?? 'lan'),
                    'ip' => isset($row['ip']) ? (string) $row['ip'] : null,
                    'bluetooth_device' => isset($row['bluetoothDevice']) ? (string) $row['bluetoothDevice'] : null,
                    'outlet_id' => $resolvedOutletId,
                    'assigned_categories' => isset($row['assignedCategories']) && is_array($row['assignedCategories'])
                        ? $row['assignedCategories']
                        : null,
                ],
            );
        }
    }

    /** @param  list<mixed>  $rows */
    private function seedPaymentMethods(array $rows): void
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $row['id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            PaymentMethod::query()->updateOrCreate(
                ['id' => $id],
                [
                    'name' => (string) ($row['name'] ?? ''),
                    'type' => (string) ($row['type'] ?? 'cash'),
                    'integration' => isset($row['integration']) ? (string) $row['integration'] : null,
                    'fee' => isset($row['fee']) ? $row['fee'] : null,
                    'status' => (string) ($row['status'] ?? 'active'),
                ],
            );
        }
    }

    /** @param  list<mixed>  $rows */
    private function seedBankAccounts(array $rows): void
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }
            $id = $row['id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }
            BankAccount::query()->updateOrCreate(
                ['id' => $id],
                [
                    'bank_name' => (string) ($row['bankName'] ?? ''),
                    'account_name' => (string) ($row['accountName'] ?? ''),
                    'account_number' => (string) ($row['accountNumber'] ?? ''),
                    'is_default' => filter_var($row['isDefault'] ?? false, FILTER_VALIDATE_BOOLEAN),
                ],
            );
        }
    }

    /** @param  array<string, mixed>  $s */
    private function seedSystem(array $s): void
    {
        if ($s === []) {
            return;
        }
        SystemSetting::query()->updateOrCreate(
            ['id' => self::SINGLETON_ID],
            [
                'enable_split_bill' => filter_var($s['enableSplitBill'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'enable_multi_payment' => filter_var($s['enableMultiPayment'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'confirm_before_payment' => filter_var($s['confirmBeforePayment'] ?? true, FILTER_VALIDATE_BOOLEAN),
                'enable_qr_ordering' => filter_var($s['enableQROrdering'] ?? true, FILTER_VALIDATE_BOOLEAN),
            ],
        );
    }

    /** @param  array<string, mixed>  $i */
    private function seedIntegration(array $i): void
    {
        IntegrationSetting::query()->updateOrCreate(
            ['id' => self::SINGLETON_ID],
            [
                'payment_gateway_key' => isset($i['paymentGatewayKey']) ? (string) $i['paymentGatewayKey'] : null,
                'webhook_url' => isset($i['webhookUrl']) ? (string) $i['webhookUrl'] : null,
                'print_agent_url' => isset($i['printAgentUrl']) ? (string) $i['printAgentUrl'] : null,
                'third_party_notes' => isset($i['thirdPartyNotes']) ? (string) $i['thirdPartyNotes'] : null,
            ],
        );
    }

    /** @param  array<string, mixed>  $n */
    private function seedNumbering(array $n): void
    {
        if ($n === []) {
            return;
        }
        NumberingSetting::query()->updateOrCreate(
            ['id' => self::SINGLETON_ID],
            [
                'invoice_format' => (string) ($n['invoiceFormat'] ?? ''),
                'order_format' => (string) ($n['orderFormat'] ?? ''),
            ],
        );
    }
}
