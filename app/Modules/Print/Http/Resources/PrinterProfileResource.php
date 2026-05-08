<?php

namespace App\Modules\Print\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PrinterProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'code' => (string) $this->code,
            'name' => (string) $this->name,
            'station' => $this->station,
            'connectionType' => (string) $this->connection_type,
            'deviceIdentifier' => $this->device_identifier,
            'ipAddress' => $this->ip_address,
            'macAddress' => $this->mac_address,
            'bluetoothName' => $this->bluetooth_name,
            'bluetoothAddress' => $this->bluetooth_address,
            'pairingState' => $this->pairing_state,
            'lastConnectedAt' => $this->last_connected_at?->toIso8601String(),
            'reconnectMetadata' => $this->reconnect_metadata,
            'signalMetadata' => $this->signal_metadata,
            'endpoint' => $this->endpoint,
            'isActive' => (bool) $this->is_active,
            'healthStatus' => (string) $this->health_status,
            'queueState' => (string) $this->queue_state,
            'retryPolicy' => $this->retry_policy,
            'meta' => $this->meta,
        ];
    }
}
