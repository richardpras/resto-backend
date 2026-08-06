<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\AccountingPostingMapping;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class AccountingPostingMappingService
{
    public const MODULE_PROCUREMENT = 'procurement';

    public const MODULE_POS = 'pos';

    public const MODULE_PAYROLL = 'payroll';

    public const MODULE_INVENTORY = 'inventory';

    /** @var list<string> */
    public const SUPPORTED_MODULES = [
        self::MODULE_PROCUREMENT,
        self::MODULE_POS,
        self::MODULE_PAYROLL,
        self::MODULE_INVENTORY,
    ];

    /** @var array<string, array{label: string, required: bool, accountTypes: list<string>}> */
    private const PROCUREMENT_RULES = [
        'procurement.grn.inventory' => [
            'label' => 'GRN inventory (debit)',
            'required' => true,
            'accountTypes' => ['asset'],
        ],
        'procurement.grn.grni' => [
            'label' => 'GRN GRNI (credit)',
            'required' => true,
            'accountTypes' => ['liability'],
        ],
        'procurement.invoice.grni' => [
            'label' => 'Invoice GRNI clearance (debit)',
            'required' => true,
            'accountTypes' => ['liability'],
        ],
        'procurement.invoice.accounts_payable' => [
            'label' => 'Invoice accounts payable (credit)',
            'required' => true,
            'accountTypes' => ['liability'],
        ],
        'procurement.payment.accounts_payable' => [
            'label' => 'Payment AP settlement (debit)',
            'required' => true,
            'accountTypes' => ['liability'],
        ],
        'procurement.payment.cash' => [
            'label' => 'Payment cash (credit)',
            'required' => true,
            'accountTypes' => ['asset'],
        ],
        'procurement.payment.bank' => [
            'label' => 'Payment bank default (credit)',
            'required' => true,
            'accountTypes' => ['asset'],
        ],
    ];

    /** @var array<string, array{label: string, required: bool, accountTypes: list<string>}> */
    private const PAYROLL_RULES = [
        'payroll.expense' => [
            'label' => 'Payroll expense (debit)',
            'required' => true,
            'accountTypes' => ['expense'],
        ],
        'payroll.salary_payable' => [
            'label' => 'Salary payable (credit)',
            'required' => true,
            'accountTypes' => ['liability'],
        ],
        'payroll.pph21_payable' => [
            'label' => 'PPh21 payable (credit)',
            'required' => true,
            'accountTypes' => ['liability'],
        ],
        'payroll.bpjs_payable' => [
            'label' => 'BPJS payable (credit)',
            'required' => true,
            'accountTypes' => ['liability'],
        ],
        'payroll.loan_receivable' => [
            'label' => 'Loan receivable recovery (credit)',
            'required' => true,
            'accountTypes' => ['asset'],
        ],
        'payroll.cash_advance_recovery' => [
            'label' => 'Cash advance recovery (credit)',
            'required' => true,
            'accountTypes' => ['asset'],
        ],
        'payroll.other_deductions' => [
            'label' => 'Other payroll deductions (credit)',
            'required' => true,
            'accountTypes' => ['liability'],
        ],
    ];

    /** @var array<string, array{label: string, required: bool, accountTypes: list<string>}> */
    private const POS_RULES = [
        'pos.sales.revenue' => [
            'label' => 'Sales revenue (credit)',
            'required' => true,
            'accountTypes' => ['revenue'],
        ],
        'pos.sales.cogs' => [
            'label' => 'Cost of goods sold (debit)',
            'required' => true,
            'accountTypes' => ['expense'],
        ],
        'pos.sales.inventory' => [
            'label' => 'Inventory reduction (credit)',
            'required' => true,
            'accountTypes' => ['asset'],
        ],
        'pos.redemption.gift_card' => [
            'label' => 'Gift card redemption (debit)',
            'required' => true,
            'accountTypes' => ['liability'],
        ],
        'pos.redemption.store_credit' => [
            'label' => 'Store credit redemption (debit)',
            'required' => true,
            'accountTypes' => ['liability'],
        ],
        'pos.cash.variance' => [
            'label' => 'Cash over/short (shift close)',
            'required' => true,
            'accountTypes' => ['expense', 'revenue'],
        ],
        'pos.payment.cash' => [
            'label' => 'Payment settlement — cash (debit)',
            'required' => true,
            'accountTypes' => ['asset'],
        ],
        'pos.payment.transfer' => [
            'label' => 'Payment settlement — transfer (debit)',
            'required' => true,
            'accountTypes' => ['asset'],
        ],
        'pos.payment.card' => [
            'label' => 'Payment settlement — card (debit)',
            'required' => true,
            'accountTypes' => ['asset'],
        ],
        'pos.payment.qris' => [
            'label' => 'Payment settlement — QRIS (debit)',
            'required' => true,
            'accountTypes' => ['asset'],
        ],
        'pos.payment.ewallet' => [
            'label' => 'Payment settlement — e-wallet (debit)',
            'required' => true,
            'accountTypes' => ['asset'],
        ],
        'pos.gift_card.issue.cash' => [
            'label' => 'Gift card issue — cash proceeds (debit)',
            'required' => true,
            'accountTypes' => ['asset'],
        ],
        'pos.gift_card.issue.gift_card' => [
            'label' => 'Gift card issue — gift card liability (credit)',
            'required' => true,
            'accountTypes' => ['liability'],
        ],
        'pos.gift_card.issue.store_credit' => [
            'label' => 'Gift card issue — store credit liability (credit)',
            'required' => true,
            'accountTypes' => ['liability'],
        ],
        'pos.gift_card.breakage.revenue' => [
            'label' => 'Gift card expiry breakage revenue (credit)',
            'required' => true,
            'accountTypes' => ['revenue'],
        ],
        'pos.cash.out.expense' => [
            'label' => 'POS cash out / petty cash expense (debit)',
            'required' => true,
            'accountTypes' => ['expense'],
        ],
        'pos.cash.in.contra' => [
            'label' => 'POS cash in float top-up contra (credit)',
            'required' => true,
            'accountTypes' => ['liability', 'equity', 'revenue'],
        ],
    ];

    /** @var array<string, array{label: string, required: bool, accountTypes: list<string>}> */
    private const INVENTORY_RULES = [
        'inventory.asset' => [
            'label' => 'Inventory asset (adjustment/waste)',
            'required' => true,
            'accountTypes' => ['asset'],
        ],
        'inventory.adjustment' => [
            'label' => 'Stock adjustment (counter account)',
            'required' => true,
            'accountTypes' => ['expense', 'revenue'],
        ],
        'inventory.waste' => [
            'label' => 'Waste expense (counter account)',
            'required' => true,
            'accountTypes' => ['expense'],
        ],
    ];

    public function __construct(
        private readonly AccountingAuditService $auditService,
    ) {}

    /** @return list<array{ruleKey: string, label: string, required: bool, accountTypes: list<string>}> */
    public function listRuleDefinitions(string $module): array
    {
        $rules = $this->ruleDefinitionsForModule($module);

        return array_map(
            static fn (string $ruleKey, array $meta): array => [
                'ruleKey' => $ruleKey,
                'label' => $meta['label'],
                'required' => $meta['required'],
                'accountTypes' => $meta['accountTypes'],
            ],
            array_keys($rules),
            $rules,
        );
    }

    /**
     * @return array{
     *   module: string,
     *   tenantId: ?int,
     *   outletId: ?int,
     *   rules: list<array<string,mixed>>,
     *   bankOverrides: list<array<string,mixed>>,
     *   paymentOverrides: list<array<string,mixed>>,
     *   missingRequiredCount: int
     * }
     */
    public function getMappings(?int $tenantId, ?int $outletId, string $module): array
    {
        $this->assertSupportedModule($module);

        $definitions = $this->listRuleDefinitions($module);
        $stored = $this->loadScopedMappings($tenantId, $outletId, $module);
        $accounts = $this->loadAccountsForMappings($stored->pluck('chart_account_id')->all());

        $rules = [];
        $missingRequired = 0;

        foreach ($definitions as $definition) {
            $ruleKey = $definition['ruleKey'];
            $mapping = $stored->firstWhere('rule_key', $ruleKey);
            $account = $mapping !== null ? $accounts->get((int) $mapping->chart_account_id) : null;
            $configured = $account !== null;

            if ($definition['required'] && ! $configured) {
                $missingRequired++;
            }

            $rules[] = [
                'ruleKey' => $ruleKey,
                'label' => $definition['label'],
                'required' => $definition['required'],
                'accountTypes' => $definition['accountTypes'],
                'configured' => $configured,
                'chartAccountId' => $account?->id,
                'chartAccountCode' => $account?->code,
                'chartAccountName' => $account?->name,
            ];
        }

        $bankOverrides = [];
        $paymentOverrides = [];

        if ($module === self::MODULE_PROCUREMENT) {
            $bankOverrides = $this->mapDynamicOverrides(
                $stored,
                $accounts,
                'procurement.payment.bank.',
                'bankAccountId',
            );
        }

        if ($module === self::MODULE_POS) {
            $paymentOverrides = $this->mapDynamicOverrides(
                $stored,
                $accounts,
                'pos.payment.',
                'paymentMethodCode',
                array_keys(self::POS_RULES),
            );
        }

        return [
            'module' => $module,
            'tenantId' => $tenantId,
            'outletId' => $outletId,
            'rules' => $rules,
            'bankOverrides' => $bankOverrides,
            'paymentOverrides' => $paymentOverrides,
            'missingRequiredCount' => $missingRequired,
        ];
    }

    /** @return array{module: string, missingRequiredCount: int, totalRequired: int} */
    public function getStatus(?int $tenantId, ?int $outletId, string $module): array
    {
        $payload = $this->getMappings($tenantId, $outletId, $module);
        $totalRequired = count(array_filter(
            $payload['rules'],
            static fn (array $rule): bool => (bool) $rule['required'],
        ));

        return [
            'module' => $module,
            'missingRequiredCount' => $payload['missingRequiredCount'],
            'totalRequired' => $totalRequired,
        ];
    }

    /**
     * @param  list<array{ruleKey: string, chartAccountId: int}>  $mappings
     * @param  list<array{bankAccountId: string, chartAccountId: int|null}>  $bankOverrides
     * @param  list<array{paymentMethodCode: string, chartAccountId: int|null}>  $paymentOverrides
     */
    public function updateMappings(
        ?User $actor,
        ?int $tenantId,
        ?int $outletId,
        string $module,
        array $mappings,
        array $bankOverrides = [],
        array $paymentOverrides = [],
    ): array {
        $this->assertSupportedModule($module);

        $definitions = $this->ruleDefinitionsForModule($module);
        $allowedRuleKeys = array_keys($definitions);

        foreach ($mappings as $row) {
            $ruleKey = (string) ($row['ruleKey'] ?? '');
            if (! in_array($ruleKey, $allowedRuleKeys, true)) {
                throw ValidationException::withMessages([
                    'mappings' => ["Unknown rule key: {$ruleKey}."],
                ]);
            }

            $chartAccountId = (int) ($row['chartAccountId'] ?? 0);
            $this->assertValidAccount($chartAccountId, $definitions[$ruleKey]['accountTypes'], $outletId);

            AccountingPostingMapping::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'outlet_id' => $outletId,
                    'module' => $module,
                    'rule_key' => $ruleKey,
                ],
                ['chart_account_id' => $chartAccountId],
            );
        }

        if ($module === self::MODULE_PROCUREMENT) {
            $this->syncDynamicOverrides(
                $tenantId,
                $outletId,
                $module,
                'procurement.payment.bank.',
                $bankOverrides,
                'bankAccountId',
            );
        }

        if ($module === self::MODULE_POS) {
            $this->syncDynamicOverrides(
                $tenantId,
                $outletId,
                $module,
                'pos.payment.',
                $paymentOverrides,
                'paymentMethodCode',
                array_keys(self::POS_RULES),
            );
        }

        $this->auditService->log(
            'posting_mappings_updated',
            'accounting_posting_mappings',
            0,
            $outletId,
            $actor,
            [
                'module' => $module,
                'mappingCount' => count($mappings),
                'bankOverrideCount' => count($bankOverrides),
                'paymentOverrideCount' => count($paymentOverrides),
            ],
        );

        return $this->getMappings($tenantId, $outletId, $module);
    }

    public function resolveAccountIdOrFail(?int $tenantId, int $outletId, string $module, string $ruleKey): int
    {
        $accountId = $this->resolveAccountId($tenantId, $outletId, $module, $ruleKey);
        if ($accountId === null) {
            throw new UnprocessableEntityHttpException(
                "Accounting posting mapping missing for rule [{$ruleKey}] (module: {$module}, outlet: {$outletId}). Configure mappings in Accounting → Posting Rules."
            );
        }

        return $accountId;
    }

    public function resolveBankCreditAccountId(?int $tenantId, int $outletId, ?string $bankAccountId): int
    {
        if ($bankAccountId !== null && $bankAccountId !== '') {
            $overrideKey = 'procurement.payment.bank.'.$bankAccountId;
            $overrideId = $this->resolveAccountId($tenantId, $outletId, self::MODULE_PROCUREMENT, $overrideKey);
            if ($overrideId !== null) {
                return $overrideId;
            }
        }

        return $this->resolveAccountIdOrFail($tenantId, $outletId, self::MODULE_PROCUREMENT, 'procurement.payment.bank');
    }

    public function resolvePosPaymentAccountId(
        ?int $tenantId,
        int $outletId,
        string $settlementMethod,
        ?string $paymentMethodCode = null,
    ): int {
        if ($paymentMethodCode !== null && $paymentMethodCode !== '') {
            $overrideKey = 'pos.payment.'.$paymentMethodCode;
            $overrideId = $this->resolveAccountId($tenantId, $outletId, self::MODULE_POS, $overrideKey);
            if ($overrideId !== null) {
                return $overrideId;
            }
        }

        $baseKey = match (strtolower(trim($settlementMethod))) {
            'transfer' => 'pos.payment.transfer',
            'card' => 'pos.payment.card',
            'qris' => 'pos.payment.qris',
            'ewallet' => 'pos.payment.ewallet',
            default => 'pos.payment.cash',
        };

        return $this->resolveAccountIdOrFail($tenantId, $outletId, self::MODULE_POS, $baseKey);
    }

    private function resolveAccountId(?int $tenantId, int $outletId, string $module, string $ruleKey): ?int
    {
        if ($outletId > 0) {
            foreach ($this->tenantCandidates($tenantId) as $candidateTenantId) {
                $outletRow = $this->findMappingRow($candidateTenantId, $outletId, $module, $ruleKey);
                if ($outletRow !== null) {
                    return $this->assertActiveAccountId((int) $outletRow->chart_account_id, $outletId);
                }
            }
        }

        foreach ($this->tenantCandidates($tenantId) as $candidateTenantId) {
            $tenantRow = $this->findMappingRow($candidateTenantId, null, $module, $ruleKey);
            if ($tenantRow !== null) {
                return $this->assertActiveAccountId(
                    (int) $tenantRow->chart_account_id,
                    $outletId > 0 ? $outletId : null,
                );
            }
        }

        return null;
    }

    /** @return list<?int> */
    private function tenantCandidates(?int $tenantId): array
    {
        if ($tenantId === null) {
            return [null];
        }

        return [$tenantId, null];
    }

    private function findMappingRow(?int $tenantId, ?int $outletId, string $module, string $ruleKey): ?AccountingPostingMapping
    {
        return AccountingPostingMapping::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'))
            ->when($outletId !== null && $outletId > 0, fn ($q) => $q->where('outlet_id', $outletId))
            ->when($outletId === null, fn ($q) => $q->whereNull('outlet_id'))
            ->where('module', $module)
            ->where('rule_key', $ruleKey)
            ->first();
    }

    /**
     * @param  Collection<int, AccountingPostingMapping>  $stored
     * @param  Collection<int, Account>  $accounts
     * @param  list<string>  $excludeRuleKeys
     * @return list<array<string, mixed>>
     */
    private function mapDynamicOverrides(
        Collection $stored,
        Collection $accounts,
        string $prefix,
        string $idField,
        array $excludeRuleKeys = [],
    ): array {
        return $stored
            ->filter(static function (AccountingPostingMapping $row) use ($prefix, $excludeRuleKeys): bool {
                if (! str_starts_with($row->rule_key, $prefix)) {
                    return false;
                }

                return ! in_array($row->rule_key, $excludeRuleKeys, true);
            })
            ->map(function (AccountingPostingMapping $row) use ($accounts, $prefix, $idField): array {
                $entityId = substr($row->rule_key, strlen($prefix));
                $account = $accounts->get((int) $row->chart_account_id);

                return [
                    $idField => $entityId,
                    'ruleKey' => $row->rule_key,
                    'chartAccountId' => $account?->id,
                    'chartAccountCode' => $account?->code,
                    'chartAccountName' => $account?->name,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $overrides
     * @param  list<string>  $reservedRuleKeys
     */
    private function syncDynamicOverrides(
        ?int $tenantId,
        ?int $outletId,
        string $module,
        string $prefix,
        array $overrides,
        string $idField,
        array $reservedRuleKeys = [],
    ): void {
        $incomingIds = [];

        foreach ($overrides as $override) {
            $entityId = trim((string) ($override[$idField] ?? ''));
            if ($entityId === '') {
                continue;
            }

            $incomingIds[] = $entityId;
            $ruleKey = $prefix.$entityId;
            $chartAccountId = $override['chartAccountId'] ?? null;

            if ($chartAccountId === null) {
                AccountingPostingMapping::query()
                    ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
                    ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'))
                    ->when($outletId !== null && $outletId > 0, fn ($q) => $q->where('outlet_id', $outletId))
                    ->when($outletId === null || $outletId <= 0, fn ($q) => $q->whereNull('outlet_id'))
                    ->where('module', $module)
                    ->where('rule_key', $ruleKey)
                    ->delete();

                continue;
            }

            $this->assertValidAccount((int) $chartAccountId, ['asset'], $outletId);

            AccountingPostingMapping::query()->updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'outlet_id' => $outletId,
                    'module' => $module,
                    'rule_key' => $ruleKey,
                ],
                ['chart_account_id' => (int) $chartAccountId],
            );
        }

        $existingOverrides = AccountingPostingMapping::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'))
            ->when($outletId !== null && $outletId > 0, fn ($q) => $q->where('outlet_id', $outletId))
            ->when($outletId === null || $outletId <= 0, fn ($q) => $q->whereNull('outlet_id'))
            ->where('module', $module)
            ->where('rule_key', 'like', $prefix.'%')
            ->get();

        foreach ($existingOverrides as $row) {
            if (in_array($row->rule_key, $reservedRuleKeys, true)) {
                continue;
            }

            $entityId = substr($row->rule_key, strlen($prefix));
            if (! in_array($entityId, $incomingIds, true)) {
                $row->delete();
            }
        }
    }

    /** @param list<string> $types */
    private function assertValidAccount(int $chartAccountId, array $types, ?int $outletId): void
    {
        if ($chartAccountId <= 0) {
            throw ValidationException::withMessages([
                'chartAccountId' => ['Chart account is required.'],
            ]);
        }

        $account = $this->findScopedAccount($chartAccountId, $types, $outletId);
        if ($account === null) {
            throw ValidationException::withMessages([
                'chartAccountId' => ["Account {$chartAccountId} is missing, inactive, or not allowed for this scope."],
            ]);
        }
    }

    private function assertActiveAccountId(int $accountId, ?int $outletId): ?int
    {
        $account = $this->findScopedAccount($accountId, ['asset', 'liability', 'equity', 'revenue', 'expense'], $outletId);

        return $account?->id;
    }

    /** @param list<string> $types */
    private function findScopedAccount(int $accountId, array $types, ?int $outletId): ?Account
    {
        $query = Account::query()
            ->whereKey($accountId)
            ->whereIn('type', $types)
            ->where('is_active', true);

        if ($outletId !== null && $outletId > 0) {
            $query->where(function ($q) use ($outletId): void {
                $q->whereNull('outlet_id')->orWhere('outlet_id', $outletId);
            });
        }

        return $query->first();
    }

    /** @return Collection<int, AccountingPostingMapping> */
    private function loadScopedMappings(?int $tenantId, ?int $outletId, string $module): Collection
    {
        $scopeOutletId = ($outletId !== null && $outletId > 0) ? $outletId : null;

        return AccountingPostingMapping::query()
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($tenantId === null, fn ($q) => $q->whereNull('tenant_id'))
            ->when($scopeOutletId !== null, fn ($q) => $q->where('outlet_id', $scopeOutletId))
            ->when($scopeOutletId === null, fn ($q) => $q->whereNull('outlet_id'))
            ->where('module', $module)
            ->with('chartAccount')
            ->get();
    }

    /** @param list<int> $accountIds
     * @return Collection<int, Account>
     */
    private function loadAccountsForMappings(array $accountIds): Collection
    {
        $ids = array_values(array_unique(array_filter($accountIds, static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            return collect();
        }

        return Account::query()->whereIn('id', $ids)->get()->keyBy('id');
    }

    /** @return array<string, array{label: string, required: bool, accountTypes: list<string>}> */
    private function ruleDefinitionsForModule(string $module): array
    {
        $this->assertSupportedModule($module);

        return match ($module) {
            self::MODULE_PROCUREMENT => self::PROCUREMENT_RULES,
            self::MODULE_PAYROLL => self::PAYROLL_RULES,
            self::MODULE_POS => self::POS_RULES,
            self::MODULE_INVENTORY => self::INVENTORY_RULES,
            default => [],
        };
    }

    private function assertSupportedModule(string $module): void
    {
        if (! in_array($module, self::SUPPORTED_MODULES, true)) {
            throw ValidationException::withMessages([
                'module' => ["Unsupported posting mapping module: {$module}."],
            ]);
        }
    }
}
