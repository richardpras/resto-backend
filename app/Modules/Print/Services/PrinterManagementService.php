<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Print\Domain\PrinterRoute;
use App\Models\Modules\Print\Domain\PrintJob;
use Illuminate\Support\Collection;

class PrinterManagementService
{
    public function __construct(
        private readonly PrintQueueProcessingService $queueProcessingService,
    ) {}

    /**
     * @return Collection<int,PrinterProfile>
     */
    public function listProfiles(?int $outletId = null): Collection
    {
        return PrinterProfile::query()
            ->when($outletId !== null, fn ($query) => $query->where('outlet_id', $outletId))
            ->orderBy('outlet_id')
            ->orderBy('code')
            ->get();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function createProfile(array $payload): PrinterProfile
    {
        /** @var PrinterProfile $profile */
        $profile = PrinterProfile::query()->create([
            'tenant_id' => 1,
            'outlet_id' => (int) $payload['outletId'],
            'code' => (string) $payload['code'],
            'name' => (string) $payload['name'],
            'station' => $payload['station'] ?? null,
            'connection_type' => $payload['connectionType'] ?? 'unknown',
            'device_identifier' => $payload['deviceIdentifier'] ?? null,
            'ip_address' => $payload['ipAddress'] ?? null,
            'mac_address' => $payload['macAddress'] ?? null,
            'bluetooth_name' => $payload['bluetoothName'] ?? null,
            'bluetooth_address' => $payload['bluetoothAddress'] ?? null,
            'pairing_state' => $payload['pairingState'] ?? null,
            'last_connected_at' => $payload['lastConnectedAt'] ?? null,
            'reconnect_metadata' => $payload['reconnectMetadata'] ?? null,
            'signal_metadata' => $payload['signalMetadata'] ?? null,
            'endpoint' => $payload['endpoint'] ?? null,
            'is_active' => (bool) ($payload['isActive'] ?? true),
            'retry_policy' => $payload['retryPolicy'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);

        return $profile;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function updateProfile(int $id, array $payload): PrinterProfile
    {
        /** @var PrinterProfile $profile */
        $profile = PrinterProfile::query()->findOrFail($id);
        $profile->fill([
            'name' => (string) $payload['name'],
            'station' => $payload['station'] ?? null,
            'connection_type' => $payload['connectionType'] ?? 'unknown',
            'device_identifier' => $payload['deviceIdentifier'] ?? null,
            'ip_address' => $payload['ipAddress'] ?? null,
            'mac_address' => $payload['macAddress'] ?? null,
            'bluetooth_name' => $payload['bluetoothName'] ?? null,
            'bluetooth_address' => $payload['bluetoothAddress'] ?? null,
            'pairing_state' => $payload['pairingState'] ?? null,
            'last_connected_at' => $payload['lastConnectedAt'] ?? null,
            'reconnect_metadata' => $payload['reconnectMetadata'] ?? null,
            'signal_metadata' => $payload['signalMetadata'] ?? null,
            'endpoint' => $payload['endpoint'] ?? null,
            'is_active' => (bool) ($payload['isActive'] ?? true),
            'retry_policy' => $payload['retryPolicy'] ?? null,
            'meta' => $payload['meta'] ?? null,
        ]);
        $profile->save();

        return $profile;
    }

    public function deleteProfile(int $id): void
    {
        PrinterProfile::query()->whereKey($id)->delete();
    }

    /**
     * @return Collection<int,PrinterRoute>
     */
    public function listRoutes(int $outletId): Collection
    {
        return PrinterRoute::query()
            ->where('outlet_id', $outletId)
            ->orderBy('print_type')
            ->orderBy('priority')
            ->get();
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    public function assignRoute(array $payload): PrinterRoute
    {
        $routeScope = (string) ($payload['routeScope'] ?? 'default');
        $category = $payload['sourceCategory'] ?? $payload['category'] ?? null;
        $itemId = $payload['itemId'] ?? null;
        $meta = is_array($payload['meta'] ?? null) ? $payload['meta'] : [];
        $meta['routeScope'] = $routeScope;
        if ($itemId !== null) {
            $meta['itemId'] = (int) $itemId;
        }

        /** @var PrinterRoute $route */
        $route = PrinterRoute::query()->updateOrCreate(
            [
                'outlet_id' => (int) $payload['outletId'],
                'printer_profile_id' => (int) $payload['printerProfileId'],
                'print_type' => (string) $payload['printType'],
                'route_scope' => $routeScope,
                'item_id' => $itemId !== null ? (int) $itemId : null,
                'station' => $payload['station'] ?? null,
                'category' => $category,
            ],
            [
                'tenant_id' => 1,
                'priority' => (int) ($payload['priority'] ?? 100),
                'is_active' => (bool) ($payload['isActive'] ?? true),
                'meta' => $meta === [] ? null : $meta,
            ]
        );

        return $route;
    }

    public function deleteRoute(int $id): void
    {
        PrinterRoute::query()->whereKey($id)->delete();
    }

    /**
     * @return array<string,mixed>
     */
    public function queueStatus(int $outletId): array
    {
        $pending = (int) PrintJob::query()->where('outlet_id', $outletId)->where('status', 'pending')->count();
        $failed = (int) PrintJob::query()->where('outlet_id', $outletId)->where('status', 'failed')->count();
        $doneToday = (int) PrintJob::query()
            ->where('outlet_id', $outletId)
            ->where('status', 'done')
            ->whereDate('processed_at', now()->toDateString())
            ->count();

        return [
            'outletId' => $outletId,
            'pending' => $pending,
            'failed' => $failed,
            'doneToday' => $doneToday,
        ];
    }

    public function retryJob(int $jobId, int $outletId): PrintJob
    {
        return $this->queueProcessingService->retryJob($jobId, $outletId);
    }
}
