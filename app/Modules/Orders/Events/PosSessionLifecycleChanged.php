<?php

namespace App\Modules\Orders\Events;

use App\Events\Realtime\OutletRealtimeEvent;

class PosSessionLifecycleChanged extends OutletRealtimeEvent
{
    public function __construct(
        int $outletId,
        private readonly int $sessionId,
        private readonly string $status,
        private readonly ?float $openingCash = null,
        private readonly ?float $closingCash = null,
        private readonly ?int $sequence = null,
        private readonly ?string $aggregateUpdatedAtIso = null,
        ?string $correlationId = null,
    ) {
        parent::__construct($outletId, 1, $correlationId);
    }

    protected function eventName(): string
    {
        return 'pos.session.lifecycle.changed';
    }

    protected function aggregateType(): string
    {
        return 'pos_session';
    }

    protected function aggregateId(): string
    {
        return (string) $this->sessionId;
    }

    protected function channelSuffix(): string
    {
        return 'pos-sessions';
    }

    protected function data(): array
    {
        return [
            'session_id' => $this->sessionId,
            'status' => $this->status,
            'opening_cash' => $this->openingCash,
            'closing_cash' => $this->closingCash,
            'meta' => $this->meta($this->sequence, $this->aggregateUpdatedAtIso),
        ];
    }
}
