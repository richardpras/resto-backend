<?php

namespace App\Modules\Reservations\Services;

use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\Modules\Reservations\Domain\Reservation;
use App\Models\User;

class ReservationTimelineService
{
    public function __construct(
        private readonly ReservationService $reservationService,
    ) {}

    /**
     * @return list<array{type: string, label: string, occurredAt: string|null, meta?: array<string, mixed>}>
     */
    public function timeline(User $user, int $reservationId): array
    {
        $reservation = $this->reservationService->show($user, $reservationId);
        $reservation->load(['tableAllocations.table', 'linkedOrder']);

        $events = [];

        $this->pushEvent($events, 'reservation.created', 'Reservation created', $reservation->created_at);
        $this->pushEvent($events, 'reservation.confirmed', 'Reservation confirmed', $reservation->confirmed_at);
        $this->pushEvent($events, 'reservation.checked_in', 'Checked in', $reservation->checked_in_at);
        $this->pushEvent($events, 'reservation.seated', 'Guest seated', $reservation->seated_at);
        $this->pushEvent($events, 'reservation.service_started', 'Service started', $reservation->service_started_at, [
            'linkedOrderId' => $reservation->linked_order_id,
        ]);
        $this->pushEvent($events, 'reservation.completed', 'Reservation completed', $reservation->completed_at);
        $this->pushEvent($events, 'reservation.cancelled', 'Reservation cancelled', $reservation->cancelled_at);
        $this->pushEvent($events, 'reservation.no_show', 'Marked no show', $reservation->no_show_at);

        foreach ($reservation->tableAllocations as $allocation) {
            $this->pushEvent($events, 'reservation.table_allocated', 'Table allocated', $allocation->allocated_at, [
                'tableId' => (int) $allocation->table_id,
                'tableName' => $allocation->table?->name,
            ]);
        }

        $auditEvents = PosEventLog::query()
            ->where('entity_type', 'reservation')
            ->where('entity_id', (int) $reservation->id)
            ->orderBy('occurred_at')
            ->get();

        foreach ($auditEvents as $log) {
            $this->pushEvent($events, (string) $log->event_type, $this->labelForEventType((string) $log->event_type), $log->occurred_at, [
                'source' => 'audit',
                'payload' => $log->payload,
            ]);
        }

        return collect($events)
            ->filter(fn (array $row): bool => $row['occurredAt'] !== null)
            ->sortBy('occurredAt')
            ->values()
            ->all();
    }

    /** @param list<array<string, mixed>> $events */
    private function pushEvent(
        array &$events,
        string $type,
        string $label,
        mixed $occurredAt,
        array $meta = [],
    ): void {
        if ($occurredAt === null) {
            return;
        }

        $events[] = [
            'type' => $type,
            'label' => $label,
            'occurredAt' => $occurredAt instanceof \DateTimeInterface
                ? $occurredAt->format(\DateTimeInterface::ATOM)
                : (string) $occurredAt,
            'meta' => $meta === [] ? null : $meta,
        ];
    }

    private function labelForEventType(string $eventType): string
    {
        return match ($eventType) {
            'reservation.confirmed' => 'Reservation confirmed',
            'reservation.checked_in' => 'Checked in',
            'reservation.seated' => 'Guest seated',
            'reservation.table_allocated' => 'Table allocated',
            'reservation.service_started' => 'Service started',
            'reservation.completed' => 'Reservation completed',
            'reservation.cancelled' => 'Reservation cancelled',
            'reservation.no_show' => 'Marked no show',
            default => str_replace('.', ' ', $eventType),
        };
    }
}
