<?php

namespace App\Modules\Accounting\Services;

use App\Models\Modules\Accounting\Domain\AccountingPostingFailure;
use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

final class AccountingPostingFailureService
{
    public function __construct(
        private readonly AccountingAuditService $accountingAuditService,
    ) {}

    /** @param array<string,mixed>|null $payload */
    public function record(
        string $sourceType,
        int $sourceId,
        ?int $outletId,
        string $errorCode,
        string $errorMessage,
        ?array $payload = null,
    ): AccountingPostingFailure {
        $existing = AccountingPostingFailure::query()
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->where('status', AccountingPostingFailure::STATUS_PENDING)
            ->first();

        if ($existing !== null) {
            $existing->update([
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'payload_json' => $payload,
            ]);

            return $existing->fresh();
        }

        $failure = AccountingPostingFailure::query()->create([
            'source_type' => $sourceType,
            'source_id' => $sourceId,
            'outlet_id' => $outletId,
            'error_code' => $errorCode,
            'error_message' => $errorMessage,
            'payload_json' => $payload,
            'status' => AccountingPostingFailure::STATUS_PENDING,
        ]);

        $this->accountingAuditService->log(
            'posting_failed',
            $sourceType,
            $sourceId,
            $outletId,
            null,
            ['failureId' => (int) $failure->id, 'errorCode' => $errorCode, 'message' => $errorMessage],
        );

        return $failure;
    }

    /** @return \Illuminate\Contracts\Pagination\LengthAwarePaginator<int, AccountingPostingFailure> */
    public function list(?string $status = null, int $perPage = 50)
    {
        return AccountingPostingFailure::query()
            ->when($status !== null && $status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function retry(AccountingPostingFailure $failure, ?User $actor = null): AccountingPostingFailure
    {
        abort_if($failure->status !== AccountingPostingFailure::STATUS_PENDING, Response::HTTP_UNPROCESSABLE_ENTITY, 'Only pending failures can be retried.');
        abort_if(! is_array($failure->payload_json) || $failure->payload_json === [], Response::HTTP_UNPROCESSABLE_ENTITY, 'Failure has no retry payload.');

        $this->accountingAuditService->log(
            'posting_retried',
            (string) $failure->source_type,
            (int) $failure->source_id,
            $failure->outlet_id !== null ? (int) $failure->outlet_id : null,
            $actor,
            ['failureId' => (int) $failure->id],
        );

        return DB::transaction(function () use ($failure, $actor): AccountingPostingFailure {
            $payload = $failure->payload_json;
            app(AccountingPostingIntegrityService::class)->validateBeforePost($payload);
            $journal = app(JournalPostingService::class)->post($payload);

            $failure->update([
                'status' => AccountingPostingFailure::STATUS_RESOLVED,
                'journal_id' => (int) $journal->id,
                'resolved_at' => now(),
            ]);

            $this->accountingAuditService->log(
                'posting_resolved',
                (string) $failure->source_type,
                (int) $failure->source_id,
                $failure->outlet_id !== null ? (int) $failure->outlet_id : null,
                $actor,
                ['failureId' => (int) $failure->id, 'journalId' => (int) $journal->id],
            );

            return $failure->fresh(['journal']);
        });
    }

    public function ignore(AccountingPostingFailure $failure, ?User $actor = null): AccountingPostingFailure
    {
        $failure->update([
            'status' => AccountingPostingFailure::STATUS_IGNORED,
            'resolved_at' => now(),
        ]);

        return $failure->fresh();
    }

    public function countPending(): int
    {
        return (int) AccountingPostingFailure::query()->where('status', AccountingPostingFailure::STATUS_PENDING)->count();
    }

    public function countByErrorCode(string $errorCode): int
    {
        return (int) AccountingPostingFailure::query()
            ->where('error_code', $errorCode)
            ->where('status', AccountingPostingFailure::STATUS_PENDING)
            ->count();
    }
}
