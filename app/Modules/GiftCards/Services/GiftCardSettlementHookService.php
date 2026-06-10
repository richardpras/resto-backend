<?php

namespace App\Modules\GiftCards\Services;

use App\Models\Modules\GiftCards\Domain\GiftCardRedemptionSettlement;
use App\Models\Modules\GiftCards\Domain\GiftCardEvent;
use App\Models\Modules\Payments\Domain\PaymentTransaction;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GiftCardSettlementHookService
{
    public function __construct(
        private readonly GiftCardEventLogger $eventLogger,
        private readonly GiftCardAccountingService $giftCardAccountingService,
    ) {}

    /** @param array<string,mixed> $payload */
    public function settle(array $payload): array
    {
        $outletId = (int) $payload['outletId'];
        $idempotencyKey = trim((string) $payload['idempotencyKey']);

        return DB::transaction(function () use ($payload, $outletId, $idempotencyKey): array {
            $existingEvent = GiftCardEvent::query()
                ->where('outlet_id', $outletId)
                ->where('event_type', 'settlement_hook_processed')
                ->where('event_idempotency_key', 'settle#'.$idempotencyKey)
                ->exists();
            if ($existingEvent) {
                return ['idempotent' => true, 'count' => 0];
            }

            $paymentTransactionId = isset($payload['paymentTransactionId']) ? (int) $payload['paymentTransactionId'] : null;
            if ($paymentTransactionId !== null) {
                $transaction = PaymentTransaction::query()->find($paymentTransactionId);
                if ($transaction === null || (int) $transaction->outlet_id !== $outletId) {
                    throw ValidationException::withMessages([
                        'paymentTransactionId' => ['Referenced payment transaction is invalid for this outlet.'],
                    ]);
                }
            }

            $settlements = GiftCardRedemptionSettlement::query()
                ->where('outlet_id', $outletId)
                ->whereIn('id', array_map('intval', $payload['redeemSettlementIds'] ?? []))
                ->lockForUpdate()
                ->get();
            if ($settlements->isEmpty()) {
                throw ValidationException::withMessages([
                    'redeemSettlementIds' => ['No redemption settlements found for settlement hook payload.'],
                ]);
            }

            $status = (string) $payload['settlementStatus'];
            foreach ($settlements as $settlement) {
                $settlement->update([
                    'status' => $status,
                    'settlement_reference' => (string) $payload['settlementReference'],
                    'payment_transaction_id' => $paymentTransactionId,
                    'settled_at' => $status === 'settled' ? now() : $settlement->settled_at,
                    'meta' => isset($payload['meta']) && is_array($payload['meta']) ? $payload['meta'] : $settlement->meta,
                ]);
                $this->eventLogger->log(
                    (int) $settlement->outlet_id,
                    (int) $settlement->issuance_id,
                    'settlement_'.$status,
                    'settle#'.$idempotencyKey.'#'.$settlement->id,
                    ['settlementId' => (int) $settlement->id, 'paymentTransactionId' => $paymentTransactionId]
                );
            }

            $this->eventLogger->log(
                $outletId,
                null,
                'settlement_hook_processed',
                'settle#'.$idempotencyKey,
                [
                    'settlementReference' => (string) $payload['settlementReference'],
                    'status' => $status,
                    'count' => $settlements->count(),
                ]
            );

            if ($status === 'settled') {
                foreach ($settlements as $settlement) {
                    $this->giftCardAccountingService->postSettledRedemptionRevenue($settlement->fresh());
                }
            }

            return ['idempotent' => false, 'count' => $settlements->count()];
        });
    }
}
