<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\User;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Orders\Events\PosSessionLifecycleChanged;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosSessionService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly PosTransitionValidator $transitionValidator,
        private readonly PosIdempotencyService $idempotencyService,
        private readonly PosAuditLogService $auditLogService,
        private readonly JournalPostingService $journalPostingService,
    ) {}

    /** @param array<string, mixed> $data */
    public function open(User $user, array $data): PosSession
    {
        $outletId = (int) $data['outletId'];
        $this->assertOutletAllowed($user, $outletId);

        return DB::transaction(function () use ($user, $outletId, $data): PosSession {
            return $this->idempotencyService->run(
                'pos-session.open.'.$outletId,
                $data['idempotencyKey'] ?? null,
                $data,
                function () use ($user, $outletId, $data): PosSession {
                    $existingOpen = PosSession::query()
                        ->where('outlet_id', $outletId)
                        ->where('status', 'open')
                        ->lockForUpdate()
                        ->exists();

                    if ($existingOpen) {
                        throw ValidationException::withMessages([
                            'outletId' => ['An open POS session already exists for this outlet.'],
                        ]);
                    }

                    $session = PosSession::query()->create([
                        'outlet_id' => $outletId,
                        'opened_by_user_id' => (int) $user->id,
                        'status' => 'open',
                        'opening_cash' => (float) $data['openingCash'],
                        'opened_at' => $data['openedAt'] ?? now(),
                        'notes' => $data['notes'] ?? null,
                    ]);
                    $this->auditLogService->log('session.opened', 'pos_session', (int) $session->id, (int) $outletId, $user);
                    event(new PosSessionLifecycleChanged(
                        outletId: (int) $session->outlet_id,
                        sessionId: (int) $session->id,
                        status: (string) $session->status,
                        openingCash: (float) $session->opening_cash,
                        sequence: (int) $session->id,
                        aggregateUpdatedAtIso: $session->updated_at?->toIso8601String()
                    ));

                    return $session;
                }
            );
        });
    }

    public function current(User $user, int $outletId): ?PosSession
    {
        $this->assertOutletAllowed($user, $outletId);

        return PosSession::query()
            ->where('outlet_id', $outletId)
            ->where('status', 'open')
            ->latest('id')
            ->first();
    }

    /** @param array<string, mixed> $data */
    public function close(User $user, int $sessionId, array $data): PosSession
    {
        return DB::transaction(function () use ($user, $sessionId, $data): PosSession {
            return $this->idempotencyService->run(
                'pos-session.close.'.$sessionId,
                $data['idempotencyKey'] ?? null,
                $data,
                function () use ($user, $sessionId, $data): PosSession {
                    $session = $this->findScopedSessionOrFail($user, $sessionId, true);
                    if ($session->status !== 'open') {
                        throw ValidationException::withMessages([
                            'status' => ['POS session is already closed.'],
                        ]);
                    }
                    $this->transitionValidator->assertSessionStatusTransition((string) $session->status, 'closed');

                    $closingCash = (float) $data['closingCash'];
                    $openingCash = (float) $session->opening_cash;
                    $session->fill([
                        'status' => 'closed',
                        'closing_cash' => $closingCash,
                        'cash_variance' => $closingCash - $openingCash,
                        'closed_by_user_id' => (int) $user->id,
                        'closed_at' => $data['closedAt'] ?? now(),
                        'notes' => $data['notes'] ?? $session->notes,
                    ]);
                    $session->save();

                    $this->journalPostingService->postForCashVariance(
                        (int) $session->id,
                        1,
                        (int) $session->outlet_id,
                        (float) $session->cash_variance
                    );

                    $this->auditLogService->log('session.closed', 'pos_session', (int) $session->id, (int) $session->outlet_id, $user, [
                        'closingCash' => $closingCash,
                    ]);
                    $fresh = $session->fresh();
                    if ($fresh !== null) {
                        event(new PosSessionLifecycleChanged(
                            outletId: (int) $fresh->outlet_id,
                            sessionId: (int) $fresh->id,
                            status: (string) $fresh->status,
                            openingCash: (float) $fresh->opening_cash,
                            closingCash: (float) $fresh->closing_cash,
                            sequence: (int) $fresh->id,
                            aggregateUpdatedAtIso: $fresh->updated_at?->toIso8601String()
                        ));
                    }

                    return $fresh ?? $session;
                }
            );
        });
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

    private function findScopedSessionOrFail(User $user, int $sessionId, bool $forUpdate = false): PosSession
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $query = PosSession::query()
            ->whereIn('outlet_id', $allowed)
            ->whereKey($sessionId);
        if ($forUpdate) {
            $query->lockForUpdate();
        }
        $session = $query->first();
        if ($session === null) {
            throw (new ModelNotFoundException)->setModel(PosSession::class, [(string) $sessionId]);
        }

        return $session;
    }
}
