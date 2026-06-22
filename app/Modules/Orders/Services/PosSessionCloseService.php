<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use App\Modules\Orders\Events\PosSessionLifecycleChanged;
use App\Modules\ShiftClose\Services\ShiftCloseCashReconciliationService;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosSessionCloseService
{
    public function __construct(
        private readonly PosTransitionValidator $transitionValidator,
        private readonly PosIdempotencyService $idempotencyService,
        private readonly PosAuditLogService $auditLogService,
        private readonly ShiftCloseCashReconciliationService $cashReconciliationService,
        private readonly PosSessionOrderLockService $orderLockService,
        private readonly OutletAccessResolver $outletAccessResolver,
    ) {}

    /** @return array<string, mixed> */
    public function previewClose(User $user, int $sessionId): array
    {
        $session = $this->findScopedSessionOrFail($user, $sessionId);
        if ($session->status !== 'open') {
            throw ValidationException::withMessages([
                'status' => ['POS session is not open.'],
            ]);
        }

        $drawer = $this->cashReconciliationService->reconcile(
            (int) $session->outlet_id,
            null,
            (int) $session->id,
        );

        return [
            'sessionId' => (int) $session->id,
            'outletId' => (int) $session->outlet_id,
            'defaultCashFloat' => $this->defaultCashFloatForOutlet((int) $session->outlet_id),
            'drawerReconciliation' => $drawer,
        ];
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

                    $actualCash = $this->resolveActualCash($data);
                    $drawer = $this->cashReconciliationService->reconcile(
                        (int) $session->outlet_id,
                        $actualCash,
                        (int) $session->id,
                    );

                    $expectedCash = (float) ($drawer['expected'] ?? 0);
                    $variance = $actualCash !== null ? round($actualCash - $expectedCash, 2) : null;
                    $closedAt = $data['closedAt'] ?? now();

                    $session->fill([
                        'status' => 'closed',
                        'closing_cash' => $actualCash,
                        'actual_cash' => $actualCash,
                        'expected_cash' => $expectedCash,
                        'cash_variance' => $variance,
                        'closed_by_user_id' => (int) $user->id,
                        'closed_at' => $closedAt,
                        'notes' => $data['notes'] ?? $session->notes,
                    ]);
                    $session->save();

                    $lockResult = $this->orderLockService->onSessionClosed((int) $session->id);

                    $this->auditLogService->log('session.closed', 'pos_session', (int) $session->id, (int) $session->outlet_id, $user, [
                        'actualCash' => $actualCash,
                        'expectedCash' => $expectedCash,
                        'cashVariance' => $variance,
                        'drawerReconciliation' => $drawer,
                        'releasedUnpaidOrders' => $lockResult['releasedUnpaidOrders'],
                    ]);

                    $fresh = $session->fresh();
                    if ($fresh !== null) {
                        event(new PosSessionLifecycleChanged(
                            outletId: (int) $fresh->outlet_id,
                            sessionId: (int) $fresh->id,
                            status: (string) $fresh->status,
                            openingCash: (float) $fresh->opening_cash,
                            closingCash: $fresh->closing_cash !== null ? (float) $fresh->closing_cash : null,
                            sequence: (int) $fresh->id,
                            aggregateUpdatedAtIso: $fresh->updated_at?->toIso8601String()
                        ));
                    }

                    return $fresh ?? $session;
                }
            );
        });
    }

    public function defaultCashFloatForOutlet(int $outletId): float
    {
        $outlet = Outlet::query()->whereKey($outletId)->first(['default_cash_float']);

        return round((float) ($outlet?->default_cash_float ?? 500000), 2);
    }

    /** @param array<string, mixed> $data */
    private function resolveActualCash(array $data): ?float
    {
        if (array_key_exists('actualCash', $data) && $data['actualCash'] !== null) {
            return round((float) $data['actualCash'], 2);
        }
        if (array_key_exists('closingCash', $data) && $data['closingCash'] !== null) {
            return round((float) $data['closingCash'], 2);
        }

        throw ValidationException::withMessages([
            'actualCash' => ['Actual cash count is required to close the shift.'],
        ]);
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
