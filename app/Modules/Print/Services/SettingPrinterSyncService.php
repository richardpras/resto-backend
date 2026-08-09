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
        private readonly ThermalPaperWidthResolver $thermalPaperWidthResolver,
    ) {}

    public function syncFromSettingPrinter(SettingPrinter $setting): PrinterProfile
    {
        $outletId = (int) $setting->outlet_id;
        $code = $this->profileCodeForSetting($setting);
        $station = $this->stationForType((string) $setting->printer_type);
        $connectionType = strtolower((string) $setting->connection);
        $lanHost = (string) ($setting->ip ?? '');
        $lanPort = 9100;
        if ($connectionType === 'lan' && str_contains($lanHost, ':')) {
            [$hostPart, $portPart] = array_pad(explode(':', $lanHost, 2), 2, null);
            if ($hostPart !== null && $hostPart !== '' && is_numeric($portPart)) {
                $lanHost = $hostPart;
                $lanPort = (int) $portPart;
            }
        }

        $paperWidthMeta = $this->thermalPaperWidthResolver->metaForPaperWidth(
            (string) ($setting->thermal_paper_width ?? SettingPrinter::PAPER_WIDTH_58MM),
        );
        $paperWidthMeta['autoCut'] = (bool) ($setting->auto_cut ?? true);

        $payload = [
            'outletId' => $outletId,
            'code' => $code,
            'name' => (string) $setting->name,
            'station' => $station,
            'connectionType' => $connectionType,
            'ipAddress' => $connectionType === 'lan' ? $lanHost : ($connectionType === 'shared' ? null : $setting->ip),
            'endpoint' => $connectionType === 'lan' && $lanHost !== ''
                ? 'tcp://'.$lanHost.':'.$lanPort
                : null,
            'bluetoothName' => in_array($connectionType, ['bluetooth', 'bt'], true) ? $setting->bluetooth_device : null,
            'bluetoothAddress' => in_array($connectionType, ['bluetooth', 'bt'], true)
                ? $this->extractBluetoothAddress((string) $setting->bluetooth_device)
                : null,
            'deviceIdentifier' => $connectionType === 'usb' ? (string) $setting->bluetooth_device : null,
            'isActive' => true,
            'meta' => [
                'legacySettingPrinterId' => (string) $setting->id,
                'bridge' => [
                    'deviceKey' => data_get($setting, 'meta.bridge.deviceKey'),
                ],
                'lan' => [
                    'ip' => $connectionType === 'lan' ? $lanHost : null,
                    'port' => $lanPort,
                ],
                'usb' => [
                    'devicePath' => $connectionType === 'usb' ? (string) $setting->bluetooth_device : null,
                ],
                'bluetooth' => [
                    'name' => in_array($connectionType, ['bluetooth', 'bt'], true) ? $setting->bluetooth_device : null,
                    'address' => in_array($connectionType, ['bluetooth', 'bt'], true)
                        ? $this->extractBluetoothAddress((string) $setting->bluetooth_device)
                        : null,
                    'devicePath' => in_array($connectionType, ['bluetooth', 'bt'], true)
                        ? (string) $setting->bluetooth_device
                        : null,
                ],
                'share' => [
                    'path' => in_array($connectionType, ['shared', 'share', 'windows_share', 'windows'], true)
                        ? (string) $setting->bluetooth_device
                        : null,
                    'printerName' => in_array($connectionType, ['shared', 'share', 'windows_share', 'windows'], true)
                        ? ((string) ($setting->ip ?: $setting->name))
                        : null,
                ],
                'print' => $paperWidthMeta,
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

        return $profile;
    }

    public function deleteRoutesForProfile(int $profileId): void
    {
        PrinterRoute::query()->where('printer_profile_id', $profileId)->delete();
    }

    private function syncRoutes(SettingPrinter $setting, PrinterProfile $profile): void
    {
        $printType = in_array((string) $setting->printer_type, ['cashier', 'receipt'], true) ? 'receipt' : 'kitchen';
        $categories = is_array($setting->assigned_categories) ? $setting->assigned_categories : [];
        $desiredKeys = [];

        if ($categories === []) {
            $route = $this->printerManagement->assignRoute([
                'outletId' => (int) $setting->outlet_id,
                'printerProfileId' => (int) $profile->id,
                'printType' => $printType,
                'routeScope' => 'default',
                'station' => (string) $profile->station,
                'priority' => 100,
                'isActive' => true,
            ]);
            $desiredKeys[] = $this->routeIdentityKey($route);

            $this->deleteOrphanRoutes((int) $profile->id, $desiredKeys);

            return;
        }

        foreach ($categories as $index => $category) {
            $route = $this->printerManagement->assignRoute([
                'outletId' => (int) $setting->outlet_id,
                'printerProfileId' => (int) $profile->id,
                'printType' => $printType,
                'routeScope' => 'category',
                'station' => (string) $profile->station,
                'sourceCategory' => (string) $category,
                'priority' => 10 + $index,
                'isActive' => true,
            ]);
            $desiredKeys[] = $this->routeIdentityKey($route);
        }

        $this->deleteOrphanRoutes((int) $profile->id, $desiredKeys);
    }

    /**
     * @param  list<string>  $desiredKeys
     */
    private function deleteOrphanRoutes(int $profileId, array $desiredKeys): void
    {
        $existing = PrinterRoute::query()->where('printer_profile_id', $profileId)->get();
        foreach ($existing as $route) {
            if (! in_array($this->routeIdentityKey($route), $desiredKeys, true)) {
                $route->delete();
            }
        }
    }

    private function routeIdentityKey(PrinterRoute $route): string
    {
        return implode('|', [
            (int) $route->outlet_id,
            (int) $route->printer_profile_id,
            (string) $route->print_type,
            (string) $route->route_scope,
            $route->category !== null ? (string) $route->category : '',
            $route->item_id !== null ? (string) $route->item_id : '',
            $route->production_station_id !== null ? (string) $route->production_station_id : '',
        ]);
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
            'dessert' => 'dessert',
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
