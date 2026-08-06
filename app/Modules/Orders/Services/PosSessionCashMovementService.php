<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Orders\Domain\PosSessionCashMovement;
use App\Models\User;
use App\Modules\Accounting\Services\JournalPostingService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosSessionCashMovementService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly PosIdempotencyService $idempotencyService,
        private readonly PosAuditLogService $auditLogService,
        private readonly JournalPostingService $journalPostingService,
    ) {}

    /** @return Collection<int, PosSessionCashMovement> */
    public function listForSession(User $user, int $sessionId): Collection
    {
        $session = $this->findScopedSessionOrFail($user, $sessionId);

        return PosSessionCashMovement::query()
            ->where('pos_session_id', $session->id)
            ->orderByDesc('id')
            ->get();
    }

    /**
     * @param  array{
     *   direction: string,
     *   amount: float|int|string,
     *   category: string,
     *   notes?: string|null,
     *   occurredAt?: string|null,
     *   idempotencyKey?: string|null,
     *   clientLocalRef?: string|null
     * }  $data
     */
    public function create(User $user, int $sessionId, array $data): PosSessionCashMovement
    {
        $direction = (string) $data['direction'];
        $category = (string) $data['category'];
        $amount = round((float) $data['amount'], 2);
        $clientLocalRef = isset($data['clientLocalRef']) && is_string($data['clientLocalRef']) && $data['clientLocalRef'] !== ''
            ? $data['clientLocalRef']
            : null;

        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => ['Amount must be greater than zero.']]);
        }

        if (! in_array($direction, [PosSessionCashMovement::DIRECTION_IN, PosSessionCashMovement::DIRECTION_OUT], true)) {
            throw ValidationException::withMessages(['direction' => ['Direction must be in or out.']]);
        }

        $allowedCategories = PosSessionCashMovement::categoriesForDirection($direction);
        if (! in_array($category, $allowedCategories, true)) {
            throw ValidationException::withMessages([
                'category' => ['Invalid category for direction '.$direction.'.'],
            ]);
        }

        return DB::transaction(function () use ($user, $sessionId, $data, $direction, $category, $amount, $clientLocalRef): PosSessionCashMovement {
            if ($clientLocalRef !== null) {
                $existing = PosSessionCashMovement::query()
                    ->where('client_local_ref', $clientLocalRef)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    return $existing;
                }
            }

            $session = $this->findScopedSessionOrFail($user, $sessionId, forUpdate: true);
            if ($session->status !== 'open') {
                throw ValidationException::withMessages([
                    'status' => ['POS session is not open.'],
                ]);
            }

            $idempotencyKey = $data['idempotencyKey'] ?? $clientLocalRef;

            return $this->idempotencyService->run(
                'pos-session.cash-movement.'.$session->id,
                $idempotencyKey,
                $data,
                function () use ($user, $session, $data, $direction, $category, $amount, $clientLocalRef): PosSessionCashMovement {
                    $movement = PosSessionCashMovement::query()->create([
                        'outlet_id' => (int) $session->outlet_id,
                        'pos_session_id' => (int) $session->id,
                        'direction' => $direction,
                        'amount' => $amount,
                        'category' => $category,
                        'notes' => $data['notes'] ?? null,
                        'created_by_user_id' => (int) $user->id,
                        'occurred_at' => $data['occurredAt'] ?? now(),
                        'client_local_ref' => $clientLocalRef,
                    ]);

                    $tenantId = $this->resolveTenantIdForOutlet((int) $session->outlet_id);
                    $journal = $this->journalPostingService->postForPosCashMovement(
                        (int) $movement->id,
                        $tenantId,
                        (int) $session->outlet_id,
                        $direction,
                        $amount,
                        $category,
                        $clientLocalRef,
                    );
                    if ($journal !== null) {
                        $movement->journal_id = (int) $journal->id;
                        $movement->save();
                    }

                    $this->auditLogService->log(
                        'session.cash_movement',
                        'pos_session_cash_movement',
                        (int) $movement->id,
                        (int) $session->outlet_id,
                        $user,
                        [
                            'sessionId' => (int) $session->id,
                            'direction' => $direction,
                            'amount' => $amount,
                            'category' => $category,
                            'journalId' => $movement->journal_id,
                        ],
                    );

                    return $movement->fresh() ?? $movement;
                }
            );
        });
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
            throw (new ModelNotFoundException)->setModel(PosSession::class, [$sessionId]);
        }

        return $session;
    }

    private function resolveTenantIdForOutlet(int $outletId): int
    {
        $fromOrder = DB::table('orders')
            ->where('outlet_id', $outletId)
            ->whereNotNull('tenant_id')
            ->orderByDesc('id')
            ->value('tenant_id');

        return $fromOrder !== null ? (int) $fromOrder : 1;
    }
}
