<?php

namespace App\Modules\Reservations\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Modules\Reservations\Domain\Reservation */
class ReservationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'tableId' => $this->table_id !== null ? (int) $this->table_id : null,
            'reservationCode' => (string) $this->reservation_code,
            'customerName' => (string) $this->customer_name,
            'customerPhone' => $this->customer_phone !== null ? (string) $this->customer_phone : null,
            'memberId' => $this->member_id !== null ? (int) $this->member_id : null,
            'memberNo' => $this->whenLoaded('member', fn () => $this->member?->member_no),
            'memberName' => $this->whenLoaded('member', fn () => $this->member?->displayName()),
            'partySize' => (int) $this->party_size,
            'reservationAt' => $this->reservation_at?->toISOString(),
            'confirmedAt' => $this->confirmed_at?->toISOString(),
            'checkedInAt' => $this->checked_in_at?->toISOString(),
            'seatedAt' => $this->seated_at?->toISOString(),
            'completedAt' => $this->completed_at?->toISOString(),
            'cancelledAt' => $this->cancelled_at?->toISOString(),
            'noShowAt' => $this->no_show_at?->toISOString(),
            'linkedOrderId' => $this->linked_order_id !== null ? (int) $this->linked_order_id : null,
            'serviceStartedAt' => $this->service_started_at?->toISOString(),
            'status' => (string) $this->status,
            'source' => (string) ($this->source ?? 'staff'),
            'requiredDepositAmount' => $this->required_deposit_amount !== null ? (float) $this->required_deposit_amount : null,
            'approvedDepositAmount' => $this->approved_deposit_amount !== null ? (float) $this->approved_deposit_amount : null,
            'depositReviewedAt' => $this->deposit_reviewed_at?->toISOString(),
            'depositRejectionReason' => $this->deposit_rejection_reason,
            'depositProofs' => ReservationDepositProofResource::collection($this->whenLoaded('depositProofs')),
            'linkedOrder' => $this->whenLoaded('linkedOrder', function () {
                $order = $this->linkedOrder;
                if ($order === null) {
                    return null;
                }

                return [
                    'id' => (int) $order->id,
                    'code' => (string) $order->code,
                    'subtotal' => (float) $order->subtotal,
                    'tax' => (float) $order->tax,
                    'total' => (float) $order->total,
                    'paidTotal' => (float) ($order->paid_total ?? 0),
                    'balanceDue' => (float) ($order->balance_due ?? 0),
                    'paymentStatus' => (string) $order->payment_status,
                    'items' => $order->relationLoaded('items')
                        ? $order->items->map(fn ($item): array => [
                            'id' => (int) $item->id,
                            'name' => (string) $item->name,
                            'qty' => (float) $item->qty,
                            'price' => (float) $item->price,
                        ])->values()->all()
                        : [],
                ];
            }),
            'createdAt' => $this->created_at?->toISOString(),
            'updatedAt' => $this->updated_at?->toISOString(),
        ];
    }
}
