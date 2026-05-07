<?php

namespace App\Modules\Payments\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Modules\Payments\Domain\PaymentTransaction */
class PaymentTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'orderId' => (int) $this->order_id,
            'orderSplitId' => $this->order_split_id !== null ? (int) $this->order_split_id : null,
            'outletId' => (int) $this->outlet_id,
            'provider' => (string) $this->provider,
            'externalReference' => (string) $this->external_reference,
            'idempotencyKey' => (string) $this->idempotency_key,
            'amount' => (float) $this->amount,
            'currency' => (string) $this->currency,
            'status' => (string) $this->status,
            'paymentMethod' => $this->payment_method,
            'checkoutUrl' => $this->checkout_url,
            'qrString' => $this->qr_string,
            'deeplinkUrl' => $this->deeplink_url,
            'vaNumber' => $this->va_number,
            'expiresAt' => $this->expiry_time?->toISOString(),
            'expiryTime' => $this->expiry_time?->toISOString(),
            'payloadSnapshot' => $this->payload_snapshot,
            'providerMetadataSnapshot' => $this->provider_metadata_snapshot,
            'paidAt' => $this->paid_at?->toISOString(),
            'expiredAt' => $this->expired_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
            'events' => $this->whenLoaded('events', fn (): array => $this->events
                ->map(fn ($event): array => [
                    'id' => (int) $event->id,
                    'eventType' => (string) $event->event_type,
                    'payload' => $event->payload,
                    'createdAt' => $event->created_at?->toISOString(),
                ])->values()->all()),
        ];
    }
}
