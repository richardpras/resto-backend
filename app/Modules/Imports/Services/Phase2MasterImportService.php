<?php

namespace App\Modules\Imports\Services;

use App\Models\Member;
use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\Modules\Imports\Domain\MasterImportBatch;
use App\Models\Modules\Loyalty\Domain\LoyaltyAccount;
use App\Models\User;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Imports\Support\CsvTableParser;
use App\Modules\Imports\Support\ImportSheetExtractor;
use App\Modules\Imports\Support\ImportTemplateSchema;
use App\Modules\Loyalty\Services\CustomerProfileService;
use App\Modules\Members\Services\MemberService;
use App\Modules\Settings\Services\OutletPaymentMethodConfigService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class Phase2MasterImportService
{
    /** @var list<string> */
    public const IMPORT_ORDER = [
        'chart_of_accounts',
        'opening_balances',
        'customers',
        'members',
        'outlet_payment_methods',
    ];

    /** @var array<string, string> */
    private const FILE_MAP = [
        'chart_of_accounts' => '08_chart_of_accounts.csv',
        'opening_balances' => '09_opening_balances.csv',
        'customers' => '10_customers.csv',
        'members' => '11_members.csv',
        'outlet_payment_methods' => '12_outlet_payment_methods.csv',
    ];

    /** @var list<string> */
    private const PAYMENT_METHOD_CODES = [
        'cash',
        'manual_qris',
        'gateway_qris',
        'gateway_ewallet',
        'manual_transfer',
    ];

    public function __construct(
        private readonly AccountingService $accountingService,
        private readonly CustomerProfileService $customerProfileService,
        private readonly MemberService $memberService,
        private readonly OutletPaymentMethodConfigService $outletPaymentMethodConfigService,
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function importBundle(User $user, array $payload): array
    {
        $outletId = (int) ($payload['outletId'] ?? 0);
        $tenantId = isset($payload['tenantId']) ? (int) $payload['tenantId'] : null;
        $preview = (bool) ($payload['preview'] ?? false);
        $file = $payload['file'] ?? null;

        abort_if($outletId < 1, 422, 'outletId is required.');
        abort_if(! $file instanceof UploadedFile, 422, 'ZIP file is required.');
        $this->assertOutletAllowed($user, $outletId);

        $sheets = ImportSheetExtractor::extract($file);
        $context = $this->buildContext($outletId, $tenantId);
        $sections = [];

        foreach (self::IMPORT_ORDER as $type) {
            $filename = self::FILE_MAP[$type];
            $content = $sheets[$filename] ?? '';
            $sections[$type] = $this->processSection($type, $content, $context, $user, $preview);
        }

        return $this->finalizeResult('phase2_bundle', $sections, $preview, $user, $outletId, $tenantId, $file->getClientOriginalName());
    }

    /**
     * @return array<string, mixed>
     */
    public function importType(User $user, string $type, array $payload): array
    {
        abort_unless(in_array($type, self::IMPORT_ORDER, true), 404, 'Unknown import type.');

        $outletId = (int) ($payload['outletId'] ?? 0);
        $tenantId = isset($payload['tenantId']) ? (int) $payload['tenantId'] : null;
        $preview = (bool) ($payload['preview'] ?? false);
        $csv = (string) ($payload['csv'] ?? '');

        abort_if($outletId < 1, 422, 'outletId is required.');
        abort_if(trim($csv) === '', 422, 'CSV content is required.');
        $this->assertOutletAllowed($user, $outletId);

        $context = $this->buildContext($outletId, $tenantId);
        $section = $this->processSection($type, $csv, $context, $user, $preview);

        return $this->finalizeResult(
            'phase2_'.$type,
            [$type => $section],
            $preview,
            $user,
            $outletId,
            $tenantId,
            (string) ($payload['filename'] ?? self::FILE_MAP[$type]),
        );
    }

    /**
     * @return array{outletId:int,tenantId:?int,accountByCode:array<string,Account>,customerByCode:array<string,LoyaltyAccount>,memberByCode:array<string,Member>,paymentMethodRows:list<array<string,mixed>>}
     */
    private function buildContext(int $outletId, ?int $tenantId): array
    {
        $accounts = Account::query()->get()->keyBy(fn (Account $row) => strtolower((string) $row->code));

        $customerQuery = LoyaltyAccount::query()
            ->where('outlet_id', $outletId)
            ->whereNull('merged_into_account_id');
        $customers = $customerQuery->get()->keyBy(fn (LoyaltyAccount $row) => strtolower((string) $row->import_code));

        $members = Member::query()
            ->where('outlet_id', $outletId)
            ->get()
            ->keyBy(fn (Member $row) => strtolower((string) $row->import_code));

        return [
            'outletId' => $outletId,
            'tenantId' => $tenantId,
            'accountByCode' => $accounts->all(),
            'customerByCode' => $customers->all(),
            'memberByCode' => $members->all(),
            'paymentMethodRows' => [],
        ];
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}
     */
    private function processSection(string $type, string $csv, array &$context, User $user, bool $preview): array
    {
        $rows = CsvTableParser::parse(
            $csv,
            ImportTemplateSchema::columnSpecsForFilename('phase2', self::FILE_MAP[$type] ?? ''),
        );
        $result = ['created' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => [], 'previewRows' => []];

        $execute = function () use ($type, $rows, &$context, $user, $preview, &$result): void {
            switch ($type) {
                case 'chart_of_accounts':
                    $this->importChartOfAccounts($rows, $context, $preview, $result);
                    break;
                case 'opening_balances':
                    $this->importOpeningBalances($rows, $context, $user, $preview, $result);
                    break;
                case 'customers':
                    $this->importCustomers($rows, $context, $user, $preview, $result);
                    break;
                case 'members':
                    $this->importMembers($rows, $context, $user, $preview, $result);
                    break;
                case 'outlet_payment_methods':
                    $this->importOutletPaymentMethods($rows, $context, $user, $preview, $result);
                    break;
            }
        };

        if (! $preview) {
            DB::transaction($execute);
        } else {
            $execute();
        }

        return $result;
    }

    /**
     * @param  list<array{row:int,data:array<string,string>}>  $rows
     * @param  array<string, mixed>  $context
     * @param  array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}  $result
     */
    private function importChartOfAccounts(array $rows, array &$context, bool $preview, array &$result): void
    {
        $pendingParents = [];

        foreach ($rows as $entry) {
            $row = $entry['row'];
            $data = $entry['data'];
            $code = trim($data['code'] ?? '');
            $name = trim($data['name'] ?? '');
            $type = strtolower(trim($data['type'] ?? ''));

            if ($code === '' || $name === '') {
                $result['errors'][] = ['row' => $row, 'message' => 'code and name are required.'];

                continue;
            }
            if (! in_array($type, ['asset', 'liability', 'equity', 'revenue', 'expense'], true)) {
                $result['errors'][] = ['row' => $row, 'message' => 'type must be asset, liability, equity, revenue, or expense.'];

                continue;
            }

            $codeKey = strtolower($code);
            $parentCode = trim($data['parent_code'] ?? '');
            $attributes = [
                'tenant_id' => $context['tenantId'],
                'code' => $code,
                'name' => $name,
                'type' => $type,
                'subtype' => trim($data['subtype'] ?? '') ?: null,
                'category' => trim($data['category'] ?? '') ?: null,
                'description' => trim($data['description'] ?? '') ?: null,
                'is_active' => $this->toBool($data['active'] ?? '1'),
            ];

            $existing = $context['accountByCode'][$codeKey] ?? Account::query()->where('code', $code)->first();
            if ($existing instanceof Account) {
                $result['previewRows'][] = ['code' => $code, 'action' => 'update'];
                if ($preview) {
                    $result['updated']++;

                    continue;
                }
                $this->accountingService->updateAccount($existing, $attributes);
                $context['accountByCode'][$codeKey] = $existing->fresh() ?? $existing;
                $result['updated']++;

                continue;
            }

            $result['previewRows'][] = ['code' => $code, 'action' => 'create'];
            if ($preview) {
                $stub = new Account($attributes);
                $stub->id = -$row;
                $stub->code = $code;
                $context['accountByCode'][$codeKey] = $stub;
                $result['created']++;
                if ($parentCode !== '') {
                    $pendingParents[] = ['codeKey' => $codeKey, 'parentCode' => $parentCode, 'row' => $row];
                }

                continue;
            }

            $account = $this->accountingService->createAccount($attributes);
            $context['accountByCode'][$codeKey] = $account;
            $result['created']++;
            if ($parentCode !== '') {
                $pendingParents[] = ['codeKey' => $codeKey, 'parentCode' => $parentCode, 'row' => $row];
            }
        }

        if ($preview || $pendingParents === []) {
            return;
        }

        foreach ($pendingParents as $link) {
            $account = $context['accountByCode'][$link['codeKey']] ?? null;
            if (! $account instanceof Account || $account->id < 1) {
                continue;
            }
            $parent = $context['accountByCode'][strtolower($link['parentCode'])] ?? null;
            if (! $parent instanceof Account) {
                $result['errors'][] = ['row' => $link['row'], 'message' => "Parent account [{$link['parentCode']}] not found."];

                continue;
            }
            $this->accountingService->updateAccount($account, ['parent_id' => (int) $parent->id]);
        }
    }

    /**
     * @param  list<array{row:int,data:array<string,string>}>  $rows
     * @param  array<string, mixed>  $context
     * @param  array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}  $result
     */
    private function importOpeningBalances(array $rows, array $context, User $user, bool $preview, array &$result): void
    {
        if ($rows === []) {
            return;
        }

        $outletId = (int) $context['outletId'];
        if (! $preview && $this->hasOpeningJournal($outletId)) {
            $result['skipped'] = count($rows);
            $result['previewRows'][] = ['action' => 'skipped', 'reason' => 'Opening journal already exists for outlet.'];

            return;
        }

        $lines = [];
        $journalDate = now()->toDateString();
        $totalDebit = 0.0;
        $totalCredit = 0.0;

        foreach ($rows as $entry) {
            $row = $entry['row'];
            $data = $entry['data'];
            $accountCode = trim($data['account_code'] ?? '');
            $debit = $this->toFloat($data['debit'] ?? '0');
            $credit = $this->toFloat($data['credit'] ?? '0');

            if ($accountCode === '') {
                $result['errors'][] = ['row' => $row, 'message' => 'account_code is required.'];

                continue;
            }
            if ($debit <= 0 && $credit <= 0) {
                $result['skipped']++;

                continue;
            }
            if ($debit > 0 && $credit > 0) {
                $result['errors'][] = ['row' => $row, 'message' => 'Use either debit or credit, not both.'];

                continue;
            }

            $account = $context['accountByCode'][strtolower($accountCode)] ?? null;
            if (! $account instanceof Account) {
                $result['errors'][] = ['row' => $row, 'message' => "Account code [{$accountCode}] not found."];

                continue;
            }

            $rowJournalDate = trim($data['journal_date'] ?? '');
            if ($rowJournalDate !== '') {
                $journalDate = $rowJournalDate;
            }

            $lines[] = [
                'account_id' => (int) $account->id,
                'debit' => $debit,
                'credit' => $credit,
                'memo' => trim($data['memo'] ?? '') ?: null,
            ];
            $totalDebit += $debit;
            $totalCredit += $credit;
        }

        if ($lines === []) {
            return;
        }

        if (abs($totalDebit - $totalCredit) > 0.0001) {
            $result['errors'][] = [
                'row' => 0,
                'message' => sprintf('Opening balances are not balanced (debit %.2f vs credit %.2f).', $totalDebit, $totalCredit),
            ];

            return;
        }

        $result['previewRows'][] = [
            'journalDate' => $journalDate,
            'lineCount' => count($lines),
            'totalDebit' => $totalDebit,
        ];

        if ($preview) {
            $result['created'] = 1;

            return;
        }

        $journal = $this->accountingService->createJournal([
            'tenant_id' => $context['tenantId'],
            'outlet_id' => $outletId,
            'journal_date' => $journalDate,
            'description' => 'Master import opening balances',
            'source_type' => 'master_import_opening',
            'source_id' => (string) $outletId,
            'created_by' => $user->id,
            'status' => 'draft',
            'lines' => $lines,
        ]);
        $this->accountingService->postJournal($journal);
        $result['created'] = 1;
    }

    /**
     * @param  list<array{row:int,data:array<string,string>}>  $rows
     * @param  array<string, mixed>  $context
     * @param  array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}  $result
     */
    private function importCustomers(array $rows, array &$context, User $user, bool $preview, array &$result): void
    {
        foreach ($rows as $entry) {
            $row = $entry['row'];
            $data = $entry['data'];
            $code = strtolower(trim($data['code'] ?? ''));
            $name = trim($data['name'] ?? '');

            if ($code === '' || $name === '') {
                $result['errors'][] = ['row' => $row, 'message' => 'code and name are required.'];

                continue;
            }

            $attributes = [
                'name' => $name,
                'phone' => trim($data['phone'] ?? '') ?: null,
                'email' => trim($data['email'] ?? '') ?: null,
            ];

            $existing = $context['customerByCode'][$code] ?? null;
            if ($existing instanceof LoyaltyAccount) {
                $result['previewRows'][] = ['code' => $data['code'], 'action' => 'update'];
                if ($preview) {
                    $result['updated']++;

                    continue;
                }
                $existing->fill($attributes)->save();
                $result['updated']++;

                continue;
            }

            $result['previewRows'][] = ['code' => $data['code'], 'action' => 'create'];
            if ($preview) {
                $stub = new LoyaltyAccount(array_merge($attributes, ['import_code' => trim($data['code'])]));
                $stub->id = -$row;
                $context['customerByCode'][$code] = $stub;
                $result['created']++;

                continue;
            }

            $account = $this->customerProfileService->create($user, [
                'outletId' => $context['outletId'],
                'name' => $name,
                'phone' => $attributes['phone'],
                'email' => $attributes['email'],
            ]);
            $account->import_code = trim($data['code']);
            $account->save();
            $context['customerByCode'][$code] = $account;
            $result['created']++;
        }
    }

    /**
     * @param  list<array{row:int,data:array<string,string>}>  $rows
     * @param  array<string, mixed>  $context
     * @param  array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}  $result
     */
    private function importMembers(array $rows, array &$context, User $user, bool $preview, array &$result): void
    {
        foreach ($rows as $entry) {
            $row = $entry['row'];
            $data = $entry['data'];
            $code = strtolower(trim($data['code'] ?? ''));
            $fullName = trim($data['full_name'] ?? '');
            $phone = trim($data['phone'] ?? '');

            if ($code === '' || $fullName === '' || $phone === '') {
                $result['errors'][] = ['row' => $row, 'message' => 'code, full_name, and phone are required.'];

                continue;
            }

            $customerCode = strtolower(trim($data['customer_code'] ?? ''));
            $loyaltyAccountId = null;
            if ($customerCode !== '') {
                $customer = $context['customerByCode'][$customerCode] ?? null;
                if (! $customer instanceof LoyaltyAccount) {
                    $result['errors'][] = ['row' => $row, 'message' => "Customer code [{$data['customer_code']}] not found."];

                    continue;
                }
                $loyaltyAccountId = (int) $customer->id;
            }

            $payload = [
                'fullName' => $fullName,
                'phone' => $phone,
                'email' => trim($data['email'] ?? '') ?: null,
                'birthDate' => trim($data['birth_date'] ?? '') ?: null,
                'gender' => trim($data['gender'] ?? '') ?: null,
                'notes' => trim($data['notes'] ?? '') ?: null,
                'status' => strtolower(trim($data['status'] ?? 'active')),
            ];

            $existing = $context['memberByCode'][$code] ?? null;
            if ($existing instanceof Member) {
                $result['previewRows'][] = ['code' => $data['code'], 'action' => 'update'];
                if ($preview) {
                    $result['updated']++;

                    continue;
                }
                $updated = $this->memberService->update($existing, $payload);
                if ($loyaltyAccountId !== null && $loyaltyAccountId > 0) {
                    $updated->loyalty_account_id = $loyaltyAccountId;
                    $updated->save();
                }
                $result['updated']++;

                continue;
            }

            $result['previewRows'][] = ['code' => $data['code'], 'action' => 'create'];
            if ($preview) {
                $stub = new Member(['import_code' => trim($data['code']), 'full_name' => $fullName]);
                $stub->id = -$row;
                $context['memberByCode'][$code] = $stub;
                $result['created']++;

                continue;
            }

            $member = $this->memberService->create($user, array_merge($payload, [
                'outletId' => $context['outletId'],
            ]));
            $member->import_code = trim($data['code']);
            if ($loyaltyAccountId !== null && $loyaltyAccountId > 0) {
                $member->loyalty_account_id = $loyaltyAccountId;
            }
            $member->save();
            $context['memberByCode'][$code] = $member->fresh() ?? $member;
            $result['created']++;
        }
    }

    /**
     * @param  list<array{row:int,data:array<string,string>}>  $rows
     * @param  array<string, mixed>  $context
     * @param  array{created:int,updated:int,skipped:int,errors:list<array{row:int,message:string}>,previewRows:list<array<string,mixed>>}  $result
     */
    private function importOutletPaymentMethods(array $rows, array $context, User $user, bool $preview, array &$result): void
    {
        if ($rows === []) {
            return;
        }

        $syncRows = [];
        foreach ($rows as $entry) {
            $row = $entry['row'];
            $data = $entry['data'];
            $code = strtolower(trim($data['payment_method_code'] ?? ''));

            if ($code === '') {
                $result['errors'][] = ['row' => $row, 'message' => 'payment_method_code is required.'];

                continue;
            }
            if (! in_array($code, self::PAYMENT_METHOD_CODES, true)) {
                $result['errors'][] = ['row' => $row, 'message' => "Unknown payment method code [{$data['payment_method_code']}]."];

                continue;
            }

            $chartAccountCode = trim($data['chart_account_code'] ?? '');
            $chartAccountId = null;
            if ($chartAccountCode !== '') {
                $account = $context['accountByCode'][strtolower($chartAccountCode)] ?? null;
                if (! $account instanceof Account) {
                    $result['errors'][] = ['row' => $row, 'message' => "Chart account code [{$chartAccountCode}] not found."];

                    continue;
                }
                $chartAccountId = (int) $account->id;
            }

            $settings = [];
            $instructions = trim($data['instructions'] ?? '');
            if ($instructions !== '') {
                $settings['instructions'] = $instructions;
            }

            $syncRows[] = [
                'paymentMethodCode' => $code,
                'enabled' => $this->toBool($data['enabled'] ?? '1'),
                'isDefault' => $this->toBool($data['is_default'] ?? '0'),
                'displayOrder' => (int) ($data['display_order'] ?? 100),
                'provider' => trim($data['provider'] ?? '') ?: null,
                'chartAccountId' => $chartAccountId,
                'settings' => $settings,
            ];
            $result['previewRows'][] = ['paymentMethodCode' => $code, 'action' => 'sync'];
        }

        if ($syncRows === []) {
            return;
        }

        if ($preview) {
            $result['updated'] = count($syncRows);

            return;
        }

        $this->outletPaymentMethodConfigService->syncConfigs($user, (int) $context['outletId'], $syncRows);
        $result['updated'] = count($syncRows);
    }

  /**
     * @param  array<string, array<string, mixed>>  $sections
     * @return array<string, mixed>
     */
    private function finalizeResult(
        string $importType,
        array $sections,
        bool $preview,
        User $user,
        int $outletId,
        ?int $tenantId,
        string $filename,
    ): array {
        $created = 0;
        $updated = 0;
        $skipped = 0;
        $errorCount = 0;

        foreach ($sections as $section) {
            $created += (int) ($section['created'] ?? 0);
            $updated += (int) ($section['updated'] ?? 0);
            $skipped += (int) ($section['skipped'] ?? 0);
            $errorCount += count($section['errors'] ?? []);
        }

        $canCommit = $errorCount === 0;
        $batch = null;

        if (! $preview) {
            $batch = MasterImportBatch::query()->create([
                'outlet_id' => $outletId,
                'tenant_id' => $tenantId,
                'import_type' => $importType,
                'filename' => $filename,
                'created_count' => $created,
                'updated_count' => $updated,
                'skipped_count' => $skipped,
                'error_count' => $errorCount,
                'summary_json' => ['sections' => $sections],
                'created_by_user_id' => $user->id,
            ]);
        }

        return [
            'preview' => $preview,
            'canCommit' => $canCommit,
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
            'errorCount' => $errorCount,
            'sections' => $sections,
            'batchId' => $batch?->id,
        ];
    }

    private function hasOpeningJournal(int $outletId): bool
    {
        return Journal::query()
            ->where('source_type', 'master_import_opening')
            ->where('source_id', (string) $outletId)
            ->exists();
    }

    private function assertOutletAllowed(User $user, int $outletId): void
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outletId is invalid.'],
            ]);
        }
    }

    private function toFloat(string $value): float
    {
        return (float) str_replace(',', '.', trim($value));
    }

    private function toBool(string $value): bool
    {
        $normalized = strtolower(trim($value));

        return in_array($normalized, ['1', 'true', 'yes', 'y', 'active'], true);
    }
}
