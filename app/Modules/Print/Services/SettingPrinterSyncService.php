<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Print\Domain\PrinterRoute;
use App\Models\Modules\Settings\Domain\SettingPrinter;
use Illuminate\Support\Str;

class SettingPrinterSyncService
{
    public function __construct(
        private readonly PrinterManagementService $printerManagement,
    ) {}

    public function syncFromSettingPrinter(SettingPrinter $setting): PrinterProfile
    {
        $outletId = (int) $setting->outlet_id;
        $code = $this->profileCodeForSetting($setting);
        $station = $this->stationForType((string) $setting->printer_type);
        $connectionType = strtolower((string) $setting->connection);

        $payload = [
            'outletId' => $outletId,
            'code' => $code,
            'name' => (string) $setting->name,
            'station' => $station,
            'connectionType' => $connectionType,
            'ipAddress' => $setting->ip,
            'endpoint' => $setting->ip ? 'tcp://'.$setting->ip.':9100' : null,
            'bluetoothName' => $setting->bluetooth_device,
            'bluetoothAddress' => $this->extractBluetoothAddress((string) $setting->bluetooth_device),
            'deviceIdentifier' => $connectionType === 'usb' ? (string) $setting->bluetooth_device : null,
            'isActive' => true,
            'meta' => [
                'legacySettingPrinterId' => (string) $setting->id,
                'bridge' => [
                    'deviceKey' => data_get($setting, 'meta.bridge.deviceKey'),
                ],
                'lan' => [
                    'ip' => $setting->ip,
                    'port' => 9100,
                ],
                'usb' => [
                    'devicePath' => $connectionType === 'usb' ? (string) $setting->bluetooth_device : null,
                ],
                'bluetooth' => [
                    'name' => $setting->bluetooth_device,
                    'address' => $this->extractBluetoothAddress((string) $setting->bluetooth_device),
                ],
            ],
        ];

        if ($setting->printer_profile_id !== null) {
            $profile = $this->printerManagement->updateProfile((int) $setting->printer_profile_id, $payload);
        } else {
            $existing = PrinterProfile::query()
                ->where('outlet_id', $outletId)
                ->where('code', $code)
                ->first();
            $profile = $existing instanceof PrinterProfile
                ? $this->printerManagement->updateProfile((int) $existing->id, $payload)
                : $this->printerManagement->createProfile($payload);

            $setting->printer_profile_id = (int) $profile->id;
            $setting->save();
        }

        $this->syncRoutes($setting, $profile);

        return $profile->fresh() ?? $profile;
    }

    public function deleteRoutesForProfile(int $profileId): void
    {
        PrinterRoute::query()->where('printer_profile_id', $profileId)->delete();
    }

    private function syncRoutes(SettingPrinter $setting, PrinterProfile $profile): void
    {
        $printType = in_array((string) $setting->printer_type, ['cashier', 'receipt'], true) ? 'receipt' : 'kitchen';
        $categories = is_array($setting->assigned_categories) ? $setting->assigned_categories : [];

        PrinterRoute::query()
            ->where('printer_profile_id', (int) $profile->id)
            ->delete();

        if ($categories === []) {
            $this->printerManagement->assignRoute([
                'outletId' => (int) $setting->outlet_id,
                'printerProfileId' => (int) $profile->id,
                'printType' => $printType,
                'routeScope' => 'default',
                'station' => (string) $profile->station,
                'priority' => 100,
                'isActive' => true,
            ]);

            return;
        }

        foreach ($categories as $index => $category) {
            $this->printerManagement->assignRoute([
                'outletId' => (int) $setting->outlet_id,
                'printerProfileId' => (int) $profile->id,
                'printType' => $printType,
                'routeScope' => 'category',
                'station' => (string) $profile->station,
                'sourceCategory' => (string) $category,
                'priority' => 10 + $index,
                'isActive' => true,
            ]);
        }
    }

    private function profileCodeForSetting(SettingPrinter $setting): string
    {
        $slug = Str::slug((string) $setting->name, '-');
        if ($slug === '') {
            $slug = 'printer';
        }

        return substr($slug, 0, 48).'-'.substr((string) $setting->id, 0, 12);
    }

    private function stationForType(string $printerType): string
    {
        return match (strtolower($printerType)) {
            'cashier', 'receipt' => 'cashier',
            'bar' => 'bar',
            default => 'kitchen',
        };
    }

    private function extractBluetoothAddress(string $value): ?string
    {
        if (preg_match('/([0-9A-Fa-f]{2}(:[0-9A-Fa-f]{2}){5})/', $value, $matches) === 1) {
            return strtoupper($matches[1]);
        }

        return null;
    }
}
