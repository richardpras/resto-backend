<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\AccountingPostingFailure;
use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

final class AccountingPostingIntegrityService
{
    public function __construct(
        private readonly AccountingPeriodService $periodService,
    ) {}

    /** @param array<string,mixed> $payload */
    public function validateBeforePost(array $payload): void
    {
        $lines = $payload['lines'] ?? [];
        $this->assertBalancedLines($lines);

        $this->periodService->assertDateOpen(
            (string) $payload['journal_date'],
            isset($payload['tenant_id']) ? (int) $payload['tenant_id'] : null,
            isset($payload['outlet_id']) ? (int) $payload['outlet_id'] : null,
        );

        foreach ($lines as $line) {
            $accountId = (int) ($line['account_id'] ?? 0);
            if ($accountId <= 0 || ! Account::query()->where('id', $accountId)->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'accounts' => ['One or more journal line accounts are missing or inactive.'],
                ]);
            }
        }
    }

    /**
     * @param list<string> $categories
     * @param list<string> $types
     * @param list<string> $fallbackCodes
     */
    public function resolveAccountOrFail(string $category, array $fallbackCodes, array $types, ?int $outletId): Account
    {
        $account = $this->resolveAccount($category, $fallbackCodes, $types, $outletId);
        if ($account === null) {
            throw ValidationException::withMessages([
                'accounts' => ["Missing active account mapping for category: {$category}."],
            ]);
        }

        return $account;
    }

    /**
     * @param list<string> $categories Each entry: "category|type1,type2|code1,code2"
     */
    public function assertRequiredMappings(array $mappingSpecs, ?int $outletId): void
    {
        foreach ($mappingSpecs as $spec) {
            [$category, $typesCsv, $codesCsv] = array_pad(explode('|', $spec, 3), 3, '');
            $types = $typesCsv !== '' ? explode(',', $typesCsv) : ['asset'];
            $codes = $codesCsv !== '' ? explode(',', $codesCsv) : [];
            if ($this->resolveAccount($category, $codes, $types, $outletId) === null) {
                throw ValidationException::withMessages([
                    'accounts' => ["Missing active account mapping for category: {$category}."],
                ]);
            }
        }
    }

    /** @param list<array{account_id:int|string,debit:float|int|string,credit:float|int|string}> $lines */
    public function assertBalancedLines(array $lines): void
    {
        if (count($lines) < 2) {
            throw ValidationException::withMessages(['lines' => ['A journal must have at least two lines.']]);
        }
        $debit = 0.0;
        $credit = 0.0;
        foreach ($lines as $line) {
            $d = (float) $line['debit'];
            $c = (float) $line['credit'];
            if ($d < 0 || $c < 0 || ($d > 0 && $c > 0)) {
                throw ValidationException::withMessages(['lines' => ['Invalid debit/credit line values.']]);
            }
            $debit += $d;
            $credit += $c;
        }
        if (round($debit, 2) !== round($credit, 2) || $debit <= 0) {
            throw new UnprocessableEntityHttpException('Journal is not balanced.');
        }
    }

    /** @param list<string> $fallbackCodes @param list<string> $types */
    public function resolveAccount(string $category, array $fallbackCodes, array $types, ?int $outletId): ?Account
    {
        $query = Account::query()->whereIn('type', $types)->where('is_active', true);
        if ($outletId !== null && $outletId > 0) {
            $query->where(function ($q) use ($outletId): void {
                $q->where('outlet_id', $outletId)->orWhereNull('outlet_id');
            });
        }
        $byCategory = (clone $query)->where('category', $category)->orderByRaw('outlet_id is null')->first();
        if ($byCategory !== null) {
            return $byCategory;
        }
        foreach ($fallbackCodes as $code) {
            $candidate = (clone $query)->where('code', $code)->orderByRaw('outlet_id is null')->first();
            if ($candidate !== null) {
                return $candidate;
            }
        }

        return (clone $query)->orderBy('id')->first();
    }

    public function classifyError(\Throwable $e): string
    {
        $message = strtolower($e->getMessage());
        if ($e instanceof ValidationException && str_contains($message, 'mapping')) {
            return AccountingPostingFailure::ERROR_MISSING_MAPPING;
        }
        if (str_contains($message, 'not balanced')) {
            return AccountingPostingFailure::ERROR_UNBALANCED;
        }
        if (str_contains($message, 'closed accounting period')) {
            return AccountingPostingFailure::ERROR_PERIOD_LOCKED;
        }
        if (str_contains($message, 'postingkey already used')) {
            return AccountingPostingFailure::ERROR_DUPLICATE;
        }

        return AccountingPostingFailure::ERROR_POSTING;
    }
}
