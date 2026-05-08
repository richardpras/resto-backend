<?php

namespace App\Modules\Terminals\Services;

use App\Models\Modules\Terminals\Domain\TerminalDevice;
use App\Models\Modules\Terminals\Domain\TerminalSyncConflictEvent;
use App\Models\Modules\Terminals\Domain\TerminalSyncOperation;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use App\Modules\Terminals\Support\TerminalOperationType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class TerminalSyncBatchService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly TerminalSyncReplayService $replayService,
    ) {}

    /**
     * @param  array{outletId: int, deviceKey?: string|null, operations: list<array<string, mixed>>}  $input
     * @return array{results: list<array<string, mixed>>, terminalRegistered: bool}
     */
    public function processBatch(User $user, array $input): array
    {
        $outletId = (int) $input['outletId'];
        $this->assertOutletAllowed($user, $outletId);

        /** @var TerminalDevice|null $device */
        $device = null;
        $deviceKey = isset($input['deviceKey']) && is_string($input['deviceKey']) ? trim($input['deviceKey']) : '';
        if ($deviceKey !== '') {
            $device = TerminalDevice::query()
                ->where('outlet_id', $outletId)
                ->where('device_key', $deviceKey)
                ->first();
            if ($device === null) {
                throw ValidationException::withMessages([
                    'deviceKey' => ['Terminal is not registered for this outlet.'],
                ]);
            }
            if (! $device->isUsable()) {
                throw ValidationException::withMessages([
                    'deviceKey' => ['Terminal is revoked or disabled.'],
                ]);
            }
        }

        $results = [];
        foreach ($input['operations'] as $op) {
            $results[] = DB::transaction(function () use ($user, $outletId, $device, $op): array {
                return $this->processSingle($user, $outletId, $device, $op);
            });
        }

        return [
            'results' => $results,
            'terminalRegistered' => $device !== null,
        ];
    }

    /**
     * @param  array<string, mixed>  $op
     * @return array<string, mixed>
     */
    private function processSingle(User $user, int $outletId, ?TerminalDevice $terminal, array $op): array
    {
        $fingerprint = trim((string) ($op['fingerprint'] ?? ''));
        $operationType = trim((string) ($op['operationType'] ?? ''));
        /** @var array<string, mixed> $payload */
        $payload = isset($op['payload']) && is_array($op['payload']) ? $op['payload'] : [];
        $clientOccurredAt = isset($op['clientOccurredAt']) ? (string) $op['clientOccurredAt'] : null;

        if ($fingerprint === '' || strlen($fingerprint) > 128) {
            throw ValidationException::withMessages([
                'fingerprint' => ['Each operation requires a fingerprint (max 128 chars).'],
            ]);
        }

        if (! in_array($operationType, TerminalOperationType::all(), true)) {
            throw ValidationException::withMessages([
                'operationType' => ['Unsupported or missing operation type.'],
            ]);
        }

        /** @var TerminalSyncOperation|null $locked */
        $locked = TerminalSyncOperation::query()
            ->where('outlet_id', $outletId)
            ->where('fingerprint', $fingerprint)
            ->lockForUpdate()
            ->first();

        if ($locked !== null && $locked->status === TerminalSyncOperation::STATUS_APPLIED) {
            $locked->increment('duplicate_replay_hits');
            $locked->refresh();

            return [
                'fingerprint' => $fingerprint,
                'operationType' => (string) $locked->operation_type,
                'status' => 'duplicate',
                'syncOperationId' => (int) $locked->id,
                'outcomeSummary' => $locked->outcome_summary,
                'duplicateReplayHits' => (int) $locked->duplicate_replay_hits,
                'recommendation' => $locked->duplicate_recommendation
                    ?? 'This operation was already applied. Drop it from your local queue.',
            ];
        }

        try {
            $this->replayService->assertWithinReplayWindow($clientOccurredAt);
        } catch (ValidationException $replayWindowException) {
            /** @var TerminalSyncOperation $row */
            $row = $this->persistOrRecycleRow(
                $locked,
                $outletId,
                $terminal,
                $operationType,
                $fingerprint,
                $payload,
                $clientOccurredAt,
                TerminalSyncOperation::STATUS_REJECTED_STALE
            );

            return [
                'fingerprint' => $fingerprint,
                'operationType' => $operationType,
                'status' => 'rejected_stale',
                'syncOperationId' => (int) $row->id,
                'replayWindowError' => $replayWindowException->errors(),
                'recommendation' => 'Operation is older than the server replay window. Pull fresh outlet state.',
            ];
        }

        /** @var TerminalSyncOperation $row */
        $row = $this->persistOrRecycleRow(
            $locked,
            $outletId,
            $terminal,
            $operationType,
            $fingerprint,
            $payload,
            $clientOccurredAt,
            TerminalSyncOperation::STATUS_PENDING
        );

        try {
            $summary = $this->replayService->execute($user, $outletId, $terminal, $operationType, $payload);
            $duplicateRecommendation = match ($operationType) {
                TerminalOperationType::PAYMENT_TRANSACTION_INITIATE => 'Gateway initiation is server-authoritative; reuse the stored transaction id.',
                TerminalOperationType::ORDER_ADD_PAYMENTS => 'Order payments mutate balances server-side; refresh the order snapshot before retrying.',
                TerminalOperationType::PRINT_DOCUMENT_ENQUEUE => 'Defer print enqueue is idempotent per render artifact; discard duplicate fingerprints after first queue.',
                default => 'Retain outcome summary server returned; discard duplicate fingerprints.',
            };
            $row->update([
                'status' => TerminalSyncOperation::STATUS_APPLIED,
                'outcome_summary' => $summary,
                'failure_message' => null,
                'conflict_type' => null,
                'conflict_detail' => null,
                'duplicate_recommendation' => $duplicateRecommendation,
                'server_applied_at' => now(),
            ]);

            return [
                'fingerprint' => $fingerprint,
                'operationType' => $operationType,
                'status' => 'applied',
                'syncOperationId' => (int) $row->id,
                'outcomeSummary' => $summary,
            ];
        } catch (ValidationException $validationException) {
            $optimistic = $this->isOptimisticConflict($validationException);
            $row->update([
                'status' => $optimistic ? TerminalSyncOperation::STATUS_CONFLICT : TerminalSyncOperation::STATUS_FAILED,
                'failure_message' => $optimistic ? null : $this->narrowExceptionMessage($validationException),
                'conflict_type' => $optimistic ? 'optimistic_concurrency' : 'validation_rejected',
                'conflict_detail' => $validationException->errors(),
                'server_applied_at' => null,
            ]);

            if ($optimistic) {
                TerminalSyncConflictEvent::query()->create([
                    'outlet_id' => $outletId,
                    'terminal_device_id' => $terminal?->id,
                    'terminal_sync_operation_id' => (int) $row->id,
                    'conflict_type' => 'optimistic_concurrency',
                    'recommendation' => 'Reload the aggregate (order, ticket, or session), then replay with refreshed expected timestamps.',
                    'details' => $validationException->errors(),
                    'created_at' => now(),
                ]);
            }

            return [
                'fingerprint' => $fingerprint,
                'operationType' => $operationType,
                'status' => $optimistic ? 'conflict' : 'failed',
                'syncOperationId' => (int) $row->id,
                'conflict' => $validationException->errors(),
                'recommendation' => $optimistic
                    ? 'Refresh server snapshot and replay with updated expectedUpdatedAt/idempotency keys.'
                    : 'Resolve validation errors locally or discard the operation.',
            ];
        } catch (ModelNotFoundException $modelNotFoundException) {
            $row->update([
                'status' => TerminalSyncOperation::STATUS_FAILED,
                'failure_message' => 'Referenced resource no longer exists for this outlet.',
                'conflict_type' => 'not_found',
                'conflict_detail' => ['model' => $modelNotFoundException->getModel()],
                'server_applied_at' => null,
            ]);

            return [
                'fingerprint' => $fingerprint,
                'operationType' => $operationType,
                'status' => 'failed',
                'syncOperationId' => (int) $row->id,
                'recommendation' => 'Drop stale mutations that reference deleted records.',
            ];
        } catch (Throwable $throwable) {
            $row->update([
                'status' => TerminalSyncOperation::STATUS_FAILED,
                'failure_message' => $throwable->getMessage(),
                'conflict_type' => 'unexpected',
                'conflict_detail' => ['exception' => $throwable::class],
                'server_applied_at' => null,
            ]);

            return [
                'fingerprint' => $fingerprint,
                'operationType' => $operationType,
                'status' => 'failed',
                'syncOperationId' => (int) $row->id,
                'recommendation' => 'Retry later or escalate with support; unexpected server fault.',
            ];
        }
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private function persistOrRecycleRow(
        ?TerminalSyncOperation $existing,
        int $outletId,
        ?TerminalDevice $terminal,
        string $operationType,
        string $fingerprint,
        ?array $payload,
        ?string $clientOccurredAtIso,
        string $status
    ): TerminalSyncOperation {
        $clientOccurred = null;
        if (is_string($clientOccurredAtIso) && trim($clientOccurredAtIso) !== '') {
            $clientOccurred = CarbonImmutable::parse($clientOccurredAtIso);
        }

        if ($existing === null) {
            return TerminalSyncOperation::query()->create([
                'outlet_id' => $outletId,
                'terminal_device_id' => $terminal?->id,
                'operation_type' => $operationType,
                'fingerprint' => $fingerprint,
                'payload' => $payload,
                'status' => $status,
                'client_occurred_at' => $clientOccurred,
            ]);
        }

        $existing->fill([
            'terminal_device_id' => $terminal?->id ?? $existing->terminal_device_id,
            'operation_type' => $operationType,
            'payload' => $payload,
            'status' => $status,
            'client_occurred_at' => $clientOccurred,
            'failure_message' => null,
            'conflict_detail' => null,
            'conflict_type' => null,
            'outcome_summary' => null,
            'server_applied_at' => null,
        ]);
        $existing->save();

        return $existing;
    }

    private function isOptimisticConflict(ValidationException $exception): bool
    {
        $errors = $exception->errors();
        if (isset($errors['expectedUpdatedAt'])) {
            return true;
        }

        foreach ($errors as $messages) {
            foreach ($messages as $message) {
                if (is_string($message) && stripos($message, 'modified by another request') !== false) {
                    return true;
                }
            }
        }

        return false;
    }

    private function narrowExceptionMessage(ValidationException $exception): string
    {
        $errors = $exception->errors();
        $firstKey = array_key_first($errors);
        if ($firstKey === null) {
            return 'Validation failed.';
        }
        $firstMessage = $errors[$firstKey][0] ?? 'Validation failed.';

        return is_string($firstMessage) ? $firstMessage : 'Validation failed.';
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
}
