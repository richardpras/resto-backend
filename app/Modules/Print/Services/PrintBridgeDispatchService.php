<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Hardware\Domain\HardwareBridgeDevice;
use App\Models\Modules\Hardware\Domain\HardwareCommandLog;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Modules\Hardware\Services\HardwareBridgeService;
use App\Modules\Hardware\Support\HardwareCommandType;
use RuntimeException;

class PrintBridgeDispatchService
{
    public function __construct(
        private readonly HardwareBridgeService $hardwareBridge,
        private readonly PrintBridgePayloadBuilder $payloadBuilder,
    ) {}

    public function dispatch(PrintJob $job): HardwareCommandLog
    {
        if ((bool) data_get($job->printable_snapshot, 'simulate_failure') === true) {
            throw new RuntimeException('Simulated print delivery failure.');
        }

        $outletId = (int) $job->outlet_id;
        if ($outletId < 1) {
            throw new RuntimeException('Print job outlet is missing.');
        }

        $profile = $this->resolvePrinterProfile($job);
        if ($profile !== null && ! $profile->is_active) {
            throw new RuntimeException('Printer profile is inactive.');
        }

        if ($profile === null) {
            throw new RuntimeException('No printer profile resolved for print job.');
        }

        $device = $this->resolveBridgeDevice($outletId, $profile);
        $execution = $this->payloadBuilder->buildExecutionPayload($job, $profile);
        $this->assertTransportReady($execution);

        $idempotencyKey = 'print-job-'.$outletId.'-'.$job->id.'-attempt-'.max(1, (int) $job->attempts);

        $result = $this->hardwareBridge->enqueueSystemCommand(
            outletId: $outletId,
            deviceKey: (string) $device->device_key,
            commandType: HardwareCommandType::PRINT_DOCUMENT,
            idempotencyKey: $idempotencyKey,
            payload: [
                'printJobId' => (int) $job->id,
                'printJobType' => (string) $job->type,
                'printerProfileId' => (int) $profile->id,
                'printerProfileCode' => (string) $profile->code,
                'transport' => $execution['transport'],
                'host' => $execution['host'],
                'port' => $execution['port'],
                'devicePath' => $execution['devicePath'],
                'bluetoothAddress' => $execution['bluetoothAddress'],
                'document' => $execution['document'],
            ],
        );

        return $result['command'];
    }

    private function resolvePrinterProfile(PrintJob $job): ?PrinterProfile
    {
        if ($job->printer_profile_id !== null) {
            $profile = PrinterProfile::query()->find($job->printer_profile_id);
            if ($profile instanceof PrinterProfile) {
                return $profile;
            }
        }

        $code = is_string($job->printer_id) && $job->printer_id !== '' ? $job->printer_id : null;
        if ($code !== null) {
            return PrinterProfile::query()
                ->where('outlet_id', (int) $job->outlet_id)
                ->where('code', $code)
                ->first();
        }

        return null;
    }

    private function resolveBridgeDevice(int $outletId, PrinterProfile $profile): HardwareBridgeDevice
    {
        $meta = is_array($profile->meta) ? $profile->meta : [];
        $deviceKey = (string) data_get($meta, 'bridge.deviceKey', data_get($meta, 'bridge.device_key', ''));

        if ($deviceKey !== '') {
            return $this->hardwareBridge->resolveActiveDevice($outletId, $deviceKey);
        }

        return $this->hardwareBridge->resolveDefaultDeviceForOutlet($outletId);
    }

    /**
     * @param  array{transport:string,host:?string,port:?int,devicePath:?string,bluetoothAddress:?string,document:array<string,mixed>}  $execution
     */
    private function assertTransportReady(array $execution): void
    {
        $transport = (string) $execution['transport'];
        if ($transport === 'lan' && empty($execution['host'])) {
            throw new RuntimeException('LAN printer host is not configured.');
        }
        if ($transport === 'usb' && empty($execution['devicePath'])) {
            throw new RuntimeException('USB printer device path is not configured.');
        }
        if ($transport === 'bluetooth' && empty($execution['bluetoothAddress'])) {
            throw new RuntimeException('Bluetooth printer address is not configured.');
        }
    }
}
