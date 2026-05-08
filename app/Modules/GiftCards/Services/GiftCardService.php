<?php

namespace App\Modules\GiftCards\Services;

use App\Models\Modules\GiftCards\Domain\GiftCardIssuance;
use App\Models\Modules\GiftCards\Domain\GiftCardLedger;
use App\Models\Modules\GiftCards\Domain\GiftCardRedemptionSettlement;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GiftCardService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly GiftCardEventLogger $eventLogger,
    ) {}

    /** @param array<string,mixed> $payload */
    public function issue(User $user, array $payload): array
    {
        $outletId = (int) $payload['outletId'];
        $this->assertOutletScope($user, $outletId);
        $idempotencyKey = trim((string) $payload['idempotencyKey']);

        return DB::transaction(function () use ($user, $payload, $outletId, $idempotencyKey): array {
            $existingLedger = GiftCardLedger::query()
                ->where('outlet_id', $outletId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existingLedger instanceof GiftCardLedger) {
                $issuance = GiftCardIssuance::query()->findOrFail((int) $existingLedger->issuance_id);

                return ['issuance' => $issuance, 'idempotent' => true];
            }

            $issuance = GiftCardIssuance::query()->create([
                'outlet_id' => $outletId,
                'issued_by_user_id' => (int) $user->id,
                'instrument_type' => (string) $payload['instrumentType'],
                'code' => strtoupper(trim((string) $payload['code'])),
                'issued_amount' => (float) $payload['initialAmount'],
                'balance_amount' => (float) $payload['initialAmount'],
                'currency' => strtoupper((string) ($payload['currency'] ?? 'IDR')),
                'status' => 'active',
                'issued_at' => now(),
                'expires_at' => $payload['expiresAt'] ?? null,
                'meta' => isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : null,
            ]);

            GiftCardLedger::query()->create([
                'issuance_id' => (int) $issuance->id,
                'outlet_id' => $outletId,
                'created_by_user_id' => (int) $user->id,
                'transaction_type' => 'issue',
                'idempotency_key' => $idempotencyKey,
                'amount_delta' => (float) $payload['initialAmount'],
                'balance_before' => 0,
                'balance_after' => (float) $payload['initialAmount'],
                'meta' => ['instrumentType' => (string) $payload['instrumentType']],
                'occurred_at' => now(),
            ]);

            $this->eventLogger->log(
                $outletId,
                (int) $issuance->id,
                'issuance_created',
                'issue#'.$idempotencyKey,
                ['code' => (string) $issuance->code, 'amount' => (float) $issuance->issued_amount]
            );

            return ['issuance' => $issuance, 'idempotent' => false];
        });
    }

    public function check(User $user, int $outletId, string $code): GiftCardIssuance
    {
        $this->assertOutletScope($user, $outletId);
        $issuance = GiftCardIssuance::query()
            ->where('outlet_id', $outletId)
            ->where('code', strtoupper(trim($code)))
            ->first();

        if (! $issuance instanceof GiftCardIssuance) {
            throw ValidationException::withMessages(['code' => ['Gift card/store credit code is not found for this outlet.']]);
        }

        return $this->expireIfNeeded($issuance);
    }

    /** @param array<string,mixed> $payload */
    public function redeem(User $user, array $payload): array
    {
        $outletId = (int) $payload['outletId'];
        $this->assertOutletScope($user, $outletId);
        $idempotencyKey = trim((string) $payload['idempotencyKey']);

        return DB::transaction(function () use ($user, $payload, $outletId, $idempotencyKey): array {
            $existingSettlement = GiftCardRedemptionSettlement::query()
                ->where('outlet_id', $outletId)
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($existingSettlement instanceof GiftCardRedemptionSettlement) {
                $issuance = GiftCardIssuance::query()->findOrFail((int) $existingSettlement->issuance_id);

                return [
                    'issuance' => $issuance,
                    'settlement' => $existingSettlement,
                    'idempotent' => true,
                ];
            }

            $issuance = GiftCardIssuance::query()
                ->where('outlet_id', $outletId)
                ->where('code', strtoupper(trim((string) $payload['code'])))
                ->lockForUpdate()
                ->first();
            if (! $issuance instanceof GiftCardIssuance) {
                throw ValidationException::withMessages(['code' => ['Gift card/store credit code is not found for this outlet.']]);
            }

            $issuance = $this->expireIfNeeded($issuance);
            if ((string) $issuance->status !== 'active') {
                throw ValidationException::withMessages(['code' => ['Gift card/store credit is not active.']]);
            }

            $amount = (float) $payload['amount'];
            $balanceBefore = (float) $issuance->balance_amount;
            if ($balanceBefore < $amount) {
                throw ValidationException::withMessages(['amount' => ['Insufficient gift card/store credit balance.']]);
            }

            $balanceAfter = round($balanceBefore - $amount, 2);
            $ledger = GiftCardLedger::query()->create([
                'issuance_id' => (int) $issuance->id,
                'outlet_id' => $outletId,
                'created_by_user_id' => (int) $user->id,
                'transaction_type' => 'redeem',
                'idempotency_key' => 'redeem#'.$idempotencyKey,
                'reference_type' => isset($payload['referenceType']) ? (string) $payload['referenceType'] : null,
                'reference_id' => isset($payload['referenceId']) ? (string) $payload['referenceId'] : null,
                'amount_delta' => -$amount,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'meta' => isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : null,
                'occurred_at' => now(),
            ]);

            $settlement = GiftCardRedemptionSettlement::query()->create([
                'issuance_id' => (int) $issuance->id,
                'ledger_entry_id' => (int) $ledger->id,
                'outlet_id' => $outletId,
                'idempotency_key' => $idempotencyKey,
                'redeemed_amount' => $amount,
                'status' => 'pending',
                'redeemed_at' => now(),
                'meta' => isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : null,
            ]);

            $issuance->update([
                'balance_amount' => $balanceAfter,
                'status' => $balanceAfter <= 0 ? 'depleted' : 'active',
                'last_redeemed_at' => now(),
            ]);

            $this->eventLogger->log(
                $outletId,
                (int) $issuance->id,
                'redemption_created',
                'redeem#'.$idempotencyKey,
                ['amount' => $amount, 'balanceAfter' => $balanceAfter, 'settlementId' => (int) $settlement->id]
            );

            return [
                'issuance' => $issuance->fresh(),
                'settlement' => $settlement,
                'idempotent' => false,
            ];
        });
    }

    private function expireIfNeeded(GiftCardIssuance $issuance): GiftCardIssuance
    {
        if ($issuance->expires_at === null || $issuance->expires_at->isFuture() || (string) $issuance->status === 'expired') {
            return $issuance;
        }

        $before = (float) $issuance->balance_amount;
        $issuance->update([
            'status' => 'expired',
            'balance_amount' => 0,
        ]);

        GiftCardLedger::query()->create([
            'issuance_id' => (int) $issuance->id,
            'outlet_id' => (int) $issuance->outlet_id,
            'created_by_user_id' => null,
            'transaction_type' => 'expire',
            'idempotency_key' => 'expire#'.$issuance->id,
            'amount_delta' => -$before,
            'balance_before' => $before,
            'balance_after' => 0,
            'meta' => ['reason' => 'expiry'],
            'occurred_at' => now(),
        ]);

        $this->eventLogger->log(
            (int) $issuance->outlet_id,
            (int) $issuance->id,
            'issuance_expired',
            'expire#'.$issuance->id,
            ['expiredBalance' => $before]
        );

        return $issuance->fresh();
    }

    private function assertOutletScope(User $user, int $outletId): void
    {
        $allowedOutletIds = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowedOutletIds, true)) {
            throw ValidationException::withMessages(['outletId' => ['The selected outletId is invalid.']]);
        }
    }
}
