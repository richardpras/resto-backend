<?php

namespace App\Modules\Settings\Services;

use App\Modules\Print\Services\SettingPrinterSyncService;

use App\Models\Modules\Settings\Domain\BankAccount;
use App\Models\Modules\Settings\Domain\IntegrationSetting;
use App\Models\Modules\Settings\Domain\MerchantSetting;
use App\Models\Modules\Settings\Domain\NumberingSetting;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\PaymentMethod;
use App\Models\Modules\Settings\Domain\SettingPrinter;
use App\Models\Modules\Settings\Domain\SystemSetting;
use App\Models\Modules\Settings\Domain\OutletTaxAssignment;
use App\Models\Modules\Settings\Domain\Tax;
use App\Models\User;
use App\Modules\Inventory\Services\InventoryCostingPolicyService;
use App\Modules\Inventory\Services\InventoryValuationService;
use App\Modules\Inventory\Support\DeferredConsumptionTrigger;
use App\Modules\Inventory\Support\InventoryCostingMethod;
use App\Modules\Orders\Services\OrderTaxResolverService;
use App\Modules\Orders\Services\PosAuditLogService;
use App\Modules\Settings\Support\OutletAccessResolver;
use App\Modules\Settings\Support\TemplateSettingsPayload;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

class SettingsDomainService
{
    private const SINGLETON_ID = 1;

    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly SettingPrinterSyncService $settingPrinterSync,
        private readonly OutletLogoService $outletLogoService,
        private readonly InventoryCostingPolicyService $inventoryCostingPolicyService,
        private readonly InventoryValuationService $inventoryValuationService,
        private readonly PosAuditLogService $auditLogService,
    ) {}

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

    /** @return list<array<string, mixed>> */
    public function listOutletsForUser(User $user): array
    {
        return $this->scopedOutletsQueryForUser($user)
            ->orderBy('name')
            ->get()
            ->map(fn (Outlet $o) => $this->outletToCamel($o))
            ->all();
    }

    public function listOutletsForUserPaginated(User $user, int $perPage = 10, int $page = 1): LengthAwarePaginator
    {
        return $this->scopedOutletsQueryForUser($user)
            ->orderBy('name')
            ->paginate($perPage, ['*'], 'page', $page)
            ->through(fn (Outlet $o): array => $this->outletToCamel($o));
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
        if (array_key_exists('defaultCashFloat', $data)) {
            $payload['default_cash_float'] = round((float) $data['defaultCashFloat'], 2);
        }

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

    /** @param  array<string, mixed>  $data */
    public function updateOutletForUser(User $user, int $id, array $data): array
    {
        $o = $this->findScopedOutletOrFail($user, $id);

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
        if (array_key_exists('defaultCashFloat', $data)) {
            $payload['default_cash_float'] = round((float) $data['defaultCashFloat'], 2);
        }

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

    public function deleteOutletForUser(User $user, int $id): void
    {
        $this->findScopedOutletOrFail($user, $id)->delete();
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
            'effective_from' => $data['effectiveFrom'] ?? null,
            'effective_to' => $data['effectiveTo'] ?? null,
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
            'effective_from' => $data['effectiveFrom'] ?? null,
            'effective_to' => $data['effectiveTo'] ?? null,
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

    /** @return list<string> */
    public function listOutletTaxAssignmentIds(User $user, int $outletId): array
    {
        $this->findScopedOutletOrFail($user, $outletId);

        return OutletTaxAssignment::query()
            ->where('outlet_id', $outletId)
            ->pluck('tax_id')
            ->map(fn ($id): string => (string) $id)
            ->values()
            ->all();
    }

    /** @param  list<string>  $taxIds */
    public function syncOutletTaxAssignments(User $user, int $outletId, array $taxIds): array
    {
        $this->findScopedOutletOrFail($user, $outletId);

        $normalized = collect($taxIds)
            ->map(fn ($id): string => (string) $id)
            ->filter(fn (string $id): bool => $id !== '')
            ->unique()
            ->values();

        if ($normalized->isNotEmpty()) {
            $existing = Tax::query()->whereIn('id', $normalized->all())->pluck('id')->map(fn ($id): string => (string) $id);
            if ($existing->count() !== $normalized->count()) {
                throw ValidationException::withMessages(['taxIds' => ['One or more tax rules were not found.']]);
            }
        }

        OutletTaxAssignment::query()->where('outlet_id', $outletId)->delete();
        foreach ($normalized as $taxId) {
            OutletTaxAssignment::query()->create([
                'outlet_id' => $outletId,
                'tax_id' => $taxId,
            ]);
        }

        return $this->listOutletTaxAssignmentIds($user, $outletId);
    }

    /** @return list<array<string, mixed>> */
    public function listOutletTaxRulesForPos(int $outletId, ?string $asOfDate = null): array
    {
        return app(OrderTaxResolverService::class)->loadRulesForOutlet($outletId, $asOfDate);
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
            'thermal_paper_width' => $this->normalizeThermalPaperWidth($data['thermalPaperWidth'] ?? null),
            'auto_cut' => array_key_exists('autoCut', $data) ? (bool) $data['autoCut'] : true,
            'ip' => $data['ip'] ?? null,
            'bluetooth_device' => $data['bluetoothDevice'] ?? null,
            'outlet_id' => (int) $data['outletId'],
            'assigned_categories' => $data['assignedCategories'] ?? null,
        ]);
        $this->settingPrinterSync->syncFromSettingPrinter($p->fresh() ?? $p);

        return $this->printerToCamel($p->fresh() ?? $p);
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
            'thermal_paper_width' => $this->normalizeThermalPaperWidth($data['thermalPaperWidth'] ?? null),
            'auto_cut' => array_key_exists('autoCut', $data) ? (bool) $data['autoCut'] : (bool) ($p->auto_cut ?? true),
            'ip' => $data['ip'] ?? null,
            'bluetooth_device' => $data['bluetoothDevice'] ?? null,
            'outlet_id' => (int) $data['outletId'],
            'assigned_categories' => $data['assignedCategories'] ?? null,
        ]);
        $p->save();
        $this->settingPrinterSync->syncFromSettingPrinter($p->fresh() ?? $p);

        return $this->printerToCamel($p->fresh() ?? $p);
    }

    public function deletePrinter(string $id): void
    {
        $p = SettingPrinter::query()->whereKey($id)->first();
        if ($p === null) {
            throw (new ModelNotFoundException)->setModel(SettingPrinter::class, [$id]);
        }
        if ($p->printer_profile_id !== null) {
            $this->settingPrinterSync->deleteRoutesForProfile((int) $p->printer_profile_id);
        }
        $p->delete();
    }

    /** @return list<array<string, mixed>> */
    public function listPaymentMethods(): array
    {
        return PaymentMethod::query()->orderBy('name')->with('chartAccount:id,code')->get()->map(fn (PaymentMethod $p) => $this->paymentMethodToCamel($p))->all();
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
            'chart_account_id' => $data['chartAccountId'] ?? null,
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
            'chart_account_id' => $data['chartAccountId'] ?? null,
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
        return BankAccount::query()->orderBy('bank_name')->with('chartAccount:id,code')->get()->map(fn (BankAccount $b) => $this->bankToCamel($b))->all();
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
            'chart_account_id' => $data['chartAccountId'] ?? null,
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
            'chart_account_id' => $data['chartAccountId'] ?? null,
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
        $row = SystemSetting::query()->find(self::SINGLETON_ID);
        if ($row === null) {
            $s = TemplateSettingsPayload::load()['system'] ?? [];

            return [
                'enableSplitBill' => (bool) ($s['enableSplitBill'] ?? true),
                'enableMultiPayment' => (bool) ($s['enableMultiPayment'] ?? true),
                'confirmBeforePayment' => (bool) ($s['confirmBeforePayment'] ?? true),
                'enableQROrdering' => (bool) ($s['enableQROrdering'] ?? true),
                'enableCallCashier' => (bool) ($s['enableCallCashier'] ?? true),
                'enforceStockOnSale' => (bool) ($s['enforceStockOnSale'] ?? true),
                'stockEnforcementMode' => (string) ($s['stockEnforcementMode'] ?? 'deferred'),
                'allowNegativeStock' => (bool) ($s['allowNegativeStock'] ?? true),
                'inventoryCostingMethod' => (string) ($s['inventoryCostingMethod'] ?? InventoryCostingMethod::MOVING_AVERAGE),
                'deferredConsumptionTrigger' => (string) ($s['deferredConsumptionTrigger'] ?? DeferredConsumptionTrigger::SHIFT_CLOSE),
                'customerAppUrl' => $s['customerAppUrl'] ?? null,
                'employeeSelfServiceEnabled' => (bool) ($s['employeeSelfServiceEnabled'] ?? false),
            ];
        }

        return array_merge(
            $this->mapSystemRow($row),
            ['customerAppUrl' => $row->customer_app_url],
        );
    }

    public function putCustomerAppUrl(?string $customerAppUrl): void
    {
        $row = SystemSetting::query()->firstOrCreate(
            ['id' => self::SINGLETON_ID],
            [
                'enable_split_bill' => true,
                'enable_multi_payment' => true,
                'confirm_before_payment' => true,
                'enable_qr_ordering' => true,
                'enable_call_cashier' => true,
                'enforce_stock_on_sale' => true,
                'stock_enforcement_mode' => 'deferred',
                'employee_self_service_enabled' => false,
            ],
        );
        $row->customer_app_url = $customerAppUrl;
        $row->save();
    }

    /** @param  array<string, mixed>  $data */
    public function putSystem(array $data, ?User $actor = null): array
    {
        $mode = $this->resolveStockEnforcementMode($data);
        $currentMethod = $this->inventoryCostingPolicyService->getMethod();
        $nextMethod = isset($data['inventoryCostingMethod']) && is_string($data['inventoryCostingMethod'])
            ? InventoryCostingMethod::normalize($data['inventoryCostingMethod'])
            : $currentMethod;
        $forceRecalculate = (bool) ($data['forceRecalculateOnMethodChange'] ?? false);

        $this->inventoryCostingPolicyService->assertMethodChangeAllowed($currentMethod, $nextMethod, $forceRecalculate);

        $existingRow = SystemSetting::query()->find(self::SINGLETON_ID);
        $currentTrigger = DeferredConsumptionTrigger::normalize(
            (string) ($existingRow?->deferred_consumption_trigger ?? DeferredConsumptionTrigger::SHIFT_CLOSE),
        );
        $nextTrigger = isset($data['deferredConsumptionTrigger']) && is_string($data['deferredConsumptionTrigger'])
            ? DeferredConsumptionTrigger::normalize($data['deferredConsumptionTrigger'])
            : $currentTrigger;

        $row = SystemSetting::query()->updateOrCreate(
            ['id' => self::SINGLETON_ID],
            [
                'enable_split_bill' => $data['enableSplitBill'],
                'enable_multi_payment' => $data['enableMultiPayment'],
                'confirm_before_payment' => $data['confirmBeforePayment'],
                'enable_qr_ordering' => $data['enableQROrdering'],
                'enable_call_cashier' => (bool) ($data['enableCallCashier'] ?? true),
                'require_customer_approval_for_adjustments' => (bool) ($data['requireCustomerApprovalForAdjustments'] ?? false),
                'qr_pending_confirmation_ttl_minutes' => max(5, min(120, (int) ($data['qrPendingConfirmationTtlMinutes'] ?? 20))),
                'stock_enforcement_mode' => $mode,
                'enforce_stock_on_sale' => $mode === 'strict',
                'allow_negative_stock' => (bool) ($data['allowNegativeStock'] ?? true),
                'inventory_costing_method' => $nextMethod,
                'deferred_consumption_trigger' => $nextTrigger,
                'employee_self_service_enabled' => (bool) ($data['employeeSelfServiceEnabled'] ?? false),
            ],
        );

        if ($currentMethod !== $nextMethod) {
            $this->auditLogService->log(
                'inventory_costing_method_changed',
                'system_settings',
                self::SINGLETON_ID,
                null,
                $actor,
                [
                    'from' => $currentMethod,
                    'to' => $nextMethod,
                    'forceRecalculate' => $forceRecalculate,
                ],
            );

            if ($forceRecalculate) {
                $this->inventoryValuationService->recalculate(actor: $actor);
            }
        }

        return $this->mapSystemRow($row);
    }

    /** @param  array<string, mixed>  $data */
    private function resolveStockEnforcementMode(array $data): string
    {
        if (isset($data['stockEnforcementMode']) && is_string($data['stockEnforcementMode'])) {
            return \App\Modules\Inventory\Support\StockEnforcementMode::normalize($data['stockEnforcementMode']);
        }

        return \App\Modules\Inventory\Support\StockEnforcementMode::fromLegacyBoolean(
            (bool) ($data['enforceStockOnSale'] ?? true),
        );
    }

    /** @return array<string, mixed> */
    private function mapSystemRow(SystemSetting $row): array
    {
        $mode = \App\Modules\Inventory\Support\StockEnforcementMode::normalize(
            (string) ($row->stock_enforcement_mode ?? 'deferred'),
        );

        return [
            'enableSplitBill' => $row->enable_split_bill,
            'enableMultiPayment' => $row->enable_multi_payment,
            'confirmBeforePayment' => $row->confirm_before_payment,
            'enableQROrdering' => $row->enable_qr_ordering,
            'enableCallCashier' => (bool) ($row->enable_call_cashier ?? true),
            'requireCustomerApprovalForAdjustments' => (bool) ($row->require_customer_approval_for_adjustments ?? false),
            'qrPendingConfirmationTtlMinutes' => max(5, min(120, (int) ($row->qr_pending_confirmation_ttl_minutes ?? 20))),
            'enforceStockOnSale' => $mode === 'strict',
            'stockEnforcementMode' => $mode,
            'allowNegativeStock' => (bool) ($row->allow_negative_stock ?? true),
            'inventoryCostingMethod' => InventoryCostingMethod::normalize(
                (string) ($row->inventory_costing_method ?? InventoryCostingMethod::MOVING_AVERAGE),
            ),
            'deferredConsumptionTrigger' => DeferredConsumptionTrigger::normalize(
                (string) ($row->deferred_consumption_trigger ?? DeferredConsumptionTrigger::SHIFT_CLOSE),
            ),
            'employeeSelfServiceEnabled' => (bool) $row->employee_self_service_enabled,
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

    private function scopedOutletsQueryForUser(User $user): Builder
    {
        $allowedOutletIds = $this->outletAccessResolver->allowedOutletIds($user);

        return Outlet::query()->whereIn('id', $allowedOutletIds);
    }

    private function findScopedOutletOrFail(User $user, int $id): Outlet
    {
        $outlet = $this->scopedOutletsQueryForUser($user)->whereKey($id)->first();
        if ($outlet === null) {
            throw (new ModelNotFoundException)->setModel(Outlet::class, [(string) $id]);
        }

        return $outlet;
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
    public function outletToPublicArray(Outlet $o): array
    {
        return $this->outletToCamel($o);
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
            'defaultCashFloat' => round((float) ($o->default_cash_float ?? 500000), 2),
            'hasLogo' => $this->outletLogoService->hasLogo($o),
            'logoVersion' => (int) ($o->logo_version ?? 0),
        ];

        $logoUrl = $this->outletLogoService->publicUrl($o);
        if ($logoUrl !== null) {
            $base['logoUrl'] = $logoUrl;
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
            'effectiveFrom' => $t->effective_from?->toDateString(),
            'effectiveTo' => $t->effective_to?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function printerToCamel(SettingPrinter $p): array
    {
        $connection = strtolower((string) $p->connection);
        $base = [
            'id' => $p->id,
            'name' => $p->name,
            'printerType' => $p->printer_type,
            'connection' => $p->connection,
            'thermalPaperWidth' => $this->normalizeThermalPaperWidth($p->thermal_paper_width ?? null),
            'autoCut' => (bool) ($p->auto_cut ?? true),
            'outletId' => (int) $p->outlet_id,
            'printerProfileId' => $p->printer_profile_id !== null ? (int) $p->printer_profile_id : null,
        ];

        if ($connection === 'lan') {
            $lanHost = (string) ($p->ip ?? '');
            $lanPort = 9100;
            if ($lanHost !== '' && str_contains($lanHost, ':')) {
                [$hostPart, $portPart] = array_pad(explode(':', $lanHost, 2), 2, null);
                if ($hostPart !== null && $hostPart !== '' && is_numeric($portPart)) {
                    $lanHost = $hostPart;
                    $lanPort = (int) $portPart;
                }
            }
            if ($lanHost !== '') {
                $base['ip'] = $lanHost;
            }
            $base['port'] = $lanPort;
        } elseif ($connection === 'usb') {
            if ($p->bluetooth_device !== null && $p->bluetooth_device !== '') {
                $base['devicePath'] = $p->bluetooth_device;
                $base['bluetoothDevice'] = $p->bluetooth_device;
            }
        } elseif (in_array($connection, ['bluetooth', 'bt'], true)) {
            if ($p->bluetooth_device !== null && $p->bluetooth_device !== '') {
                $base['bluetoothDevice'] = $p->bluetooth_device;
                $base['devicePath'] = $p->bluetooth_device;
                if (preg_match('/([0-9A-Fa-f]{2}(:[0-9A-Fa-f]{2}){5})/', (string) $p->bluetooth_device, $matches) === 1) {
                    $base['bluetoothAddress'] = strtoupper($matches[1]);
                }
            }
        } elseif (in_array($connection, ['shared', 'share', 'windows_share', 'windows'], true)) {
            if ($p->bluetooth_device !== null && $p->bluetooth_device !== '') {
                $base['sharePath'] = $p->bluetooth_device;
                $base['bluetoothDevice'] = $p->bluetooth_device;
            }
            if ($p->ip !== null && $p->ip !== '') {
                $base['sharePrinterName'] = $p->ip;
                $base['ip'] = $p->ip;
            }
        } else {
            if ($p->ip !== null && $p->ip !== '') {
                $base['ip'] = $p->ip;
            }
            if ($p->bluetooth_device !== null && $p->bluetooth_device !== '') {
                $base['bluetoothDevice'] = $p->bluetooth_device;
            }
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
        if ($p->chart_account_id !== null) {
            $base['chartAccountId'] = (int) $p->chart_account_id;
            $base['chartAccountCode'] = $p->chartAccount?->code;
        }

        return $base;
    }

    /** @return array<string, mixed> */
    private function bankToCamel(BankAccount $b): array
    {
        $base = [
            'id' => $b->id,
            'bankName' => $b->bank_name,
            'accountName' => $b->account_name,
            'accountNumber' => $b->account_number,
            'isDefault' => $b->is_default,
        ];
        if ($b->chart_account_id !== null) {
            $base['chartAccountId'] = (int) $b->chart_account_id;
            $base['chartAccountCode'] = $b->chartAccount?->code;
        }

        return $base;
    }

    private function normalizeThermalPaperWidth(?string $width): string
    {
        return in_array($width, [SettingPrinter::PAPER_WIDTH_58MM, SettingPrinter::PAPER_WIDTH_80MM], true)
            ? $width
            : SettingPrinter::PAPER_WIDTH_58MM;
    }
}
