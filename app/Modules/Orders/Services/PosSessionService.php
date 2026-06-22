<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\User;
use App\Modules\Orders\Events\PosSessionLifecycleChanged;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosSessionService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly PosTransitionValidator $transitionValidator,
        private readonly PosIdempotencyService $idempotencyService,
        private readonly PosAuditLogService $auditLogService,
        private readonly PosSessionCloseService $closeService,
    ) {}

    /** @param array<string, mixed> $data */
    public function open(User $user, array $data): PosSession
    {
        $outletId = (int) $data['outletId'];
        $this->assertOutletAllowed($user, $outletId);

        $openingCash = array_key_exists('openingCash', $data) && $data['openingCash'] !== null
            ? round((float) $data['openingCash'], 2)
            : $this->closeService->defaultCashFloatForOutlet($outletId);

        return DB::transaction(function () use ($user, $outletId, $data, $openingCash): PosSession {
            return $this->idempotencyService->run(
                'pos-session.open.'.$outletId,
                $data['idempotencyKey'] ?? null,
                $data,
                function () use ($user, $outletId, $data, $openingCash): PosSession {
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
                        'opening_cash' => $openingCash,
                        'opened_at' => $data['openedAt'] ?? now(),
                        'notes' => $data['notes'] ?? null,
                    ]);
                    $this->auditLogService->log('session.opened', 'pos_session', (int) $session->id, (int) $outletId, $user, [
                        'openingCash' => $openingCash,
                        'defaultCashFloat' => $this->closeService->defaultCashFloatForOutlet($outletId),
                    ]);
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
        return $this->closeService->close($user, $sessionId, $data);
    }

    /** @return array<string, mixed> */
    public function previewClose(User $user, int $sessionId): array
    {
        return $this->closeService->previewClose($user, $sessionId);
    }

    public function defaultCashFloatForOutlet(int $outletId): float
    {
        return $this->closeService->defaultCashFloatForOutlet($outletId);
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
