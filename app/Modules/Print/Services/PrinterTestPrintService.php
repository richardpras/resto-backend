<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Settings\Domain\SettingPrinter;
use App\Modules\Hardware\Services\HardwareBridgeService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use RuntimeException;

class PrinterTestPrintService
{
    public function __construct(
        private readonly SettingPrinterSyncService $settingPrinterSync,
        private readonly PrintQueueProcessingService $queueProcessing,
        private readonly PrintQueueStateService $stateService,
        private readonly HardwareBridgeService $hardwareBridge,
        private readonly PrintBridgeDispatchService $bridgeDispatch,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function dispatchForSettingPrinter(string $settingPrinterId): array
    {
        $setting = SettingPrinter::query()->whereKey($settingPrinterId)->first();
        if ($setting === null) {
            throw (new ModelNotFoundException)->setModel(SettingPrinter::class, [$settingPrinterId]);
        }

        $outletId = (int) $setting->outlet_id;
        if ($outletId < 1) {
            throw new RuntimeException('Printer outlet is not configured.');
        }

        if (! $this->hardwareBridge->isBridgeOnlineForOutlet($outletId)) {
            throw new RuntimeException('Hardware bridge is offline for this outlet.');
        }

        $profile = $this->resolveProfile($setting);
        $printType = $this->printTypeForSetting($setting);
        $idempotencyKey = 'settings-test-'.$settingPrinterId.'-'.Str::uuid()->toString();
        $dedupeKey = sha1($outletId.'|printer_test|0|'.$printType.'|'.$idempotencyKey);
        $printableSnapshot = [
            'thermalText' => $this->buildTestThermalText($setting, $profile),
        ];

        $preflightJob = new PrintJob([
            'outlet_id' => $outletId,
            'type' => $printType,
            'printer_id' => (string) $profile->id,
            'printer_profile_id' => (int) $profile->id,
            'printable_snapshot' => $printableSnapshot,
        ]);
        $this->bridgeDispatch->preflight($preflightJob);

        $job = PrintJob::query()->create([
            'tenant_id' => 1,
            'outlet_id' => $outletId,
            'type' => $printType,
            'printer_id' => (string) $profile->id,
            'printer_profile_id' => (int) $profile->id,
            'source_type' => 'printer_test',
            'source_id' => 0,
            'idempotency_key' => $idempotencyKey,
            'dedupe_key' => $dedupeKey,
            'content' => [
                'sourceType' => 'printer_test',
                'sourceId' => 0,
                'type' => $printType,
                'settingPrinterId' => $settingPrinterId,
            ],
            'printable_snapshot' => $printableSnapshot,
            'status' => 'pending',
            'attempts' => 0,
            'queued_at' => now(),
            'next_retry_at' => now(),
            'max_attempts' => 3,
            'retryable' => true,
            'recovery_state' => 'none',
        ]);

        $this->stateService->appendEvent($job, 'queued', 'pending', [
            'idempotency_key' => $idempotencyKey,
            'source' => 'settings_test',
        ]);
        $this->stateService->emitLifecycle($job->fresh(), 'queued');

        $this->queueProcessing->processJob((int) $job->id, $outletId, 'settings:test-print');

        $fresh = $job->fresh();
        if ($fresh === null) {
            throw new RuntimeException('Test print job could not be loaded after dispatch.');
        }

        if ((string) $fresh->status === 'failed') {
            throw new RuntimeException((string) ($fresh->last_error ?: 'Test print dispatch failed.'));
        }

        return [
            'printJobId' => (int) $fresh->id,
            'status' => (string) $fresh->status,
            'recoveryState' => (string) $fresh->recovery_state,
            'hardwareCommandLogId' => $fresh->hardware_command_log_id !== null
                ? (int) $fresh->hardware_command_log_id
                : null,
            'printerProfileId' => (int) $profile->id,
            'outletId' => $outletId,
        ];
    }

    private function resolveProfile(SettingPrinter $setting): PrinterProfile
    {
        if ($setting->printer_profile_id === null) {
            $profile = $this->settingPrinterSync->syncFromSettingPrinter($setting->fresh() ?? $setting);
        } else {
            $profile = PrinterProfile::query()->find((int) $setting->printer_profile_id);
            if (! $profile instanceof PrinterProfile) {
                $profile = $this->settingPrinterSync->syncFromSettingPrinter($setting->fresh() ?? $setting);
            }
        }

        if (! $profile->is_active) {
            throw new RuntimeException('Printer profile is inactive.');
        }

        return $profile;
    }

    private function printTypeForSetting(SettingPrinter $setting): string
    {
        return in_array(strtolower((string) $setting->printer_type), ['cashier', 'receipt'], true)
            ? 'receipt'
            : 'kitchen';
    }

    private function buildTestThermalText(SettingPrinter $setting, PrinterProfile $profile): string
    {
        $lines = [
            '*** TEST PRINT ***',
            (string) $setting->name,
            'Profile: '.(string) $profile->code,
            'Connection: '.strtoupper((string) $profile->connection_type),
            'Time: '.now()->format('Y-m-d H:i:s'),
            '',
            'If you can read this,',
            'the print bridge works.',
            '',
        ];

        return implode("\n", $lines);
    }
}
