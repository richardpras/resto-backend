<?php

namespace App\Events\Realtime;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

abstract class OutletRealtimeEvent implements ShouldBroadcast
{
    use Dispatchable;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    public function __construct(
        protected int $outletId,
        protected int $eventVersion = 1,
        protected ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('broadcasting.queue', 'broadcasts'));
    }

    abstract protected function eventName(): string;

    abstract protected function aggregateType(): string;

    abstract protected function aggregateId(): string;

    abstract protected function channelSuffix(): string;

    /** @return array<string,mixed> */
    abstract protected function data(): array;

    protected function meta(?int $sequence = null, ?string $aggregateUpdatedAtIso = null, ?string $replayKey = null): array
    {
        return [
            'correlation_id' => $this->correlationId ?: (string) Str::uuid(),
            'sequence' => $sequence,
            'aggregate_updated_at' => $aggregateUpdatedAtIso,
            'replay_key' => $replayKey ?: (string) Str::uuid(),
        ];
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel('outlet.'.$this->outletId.'.'.$this->channelSuffix())];
    }

    public function broadcastAs(): string
    {
        return $this->eventName();
    }

    public function broadcastWith(): array
    {
        $eventId = (string) Str::uuid();
        $occurredAt = now()->toIso8601String();
        $data = $this->data();
        $meta = isset($data['meta']) && is_array($data['meta']) ? $data['meta'] : [];
        $sequence = isset($meta['sequence']) && is_numeric($meta['sequence']) ? (int) $meta['sequence'] : null;

        return [
            // Canonical envelope (new contract)
            'id' => $eventId,
            'type' => $this->eventName(),
            'sequence' => $sequence,
            'occurredAt' => $occurredAt,
            'payload' => $data,
            // Backward-compatible fields
            'event_id' => $eventId,
            'event_name' => $this->eventName(),
            'event_version' => $this->eventVersion,
            'occurred_at' => $occurredAt,
            'aggregate_type' => $this->aggregateType(),
            'aggregate_id' => $this->aggregateId(),
            'outlet_id' => $this->outletId,
            'data' => $data,
        ];
    }
}
