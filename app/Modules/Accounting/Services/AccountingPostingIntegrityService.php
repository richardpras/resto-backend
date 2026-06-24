<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Accounting\Domain\AccountingPostingFailure;
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
