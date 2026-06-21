<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Print\Domain\PrinterRoute;
use App\Models\Modules\Settings\Domain\SettingPrinter;
use Illuminate\Support\Facades\Log;

class CashierPrinterResolver
{
    public function __construct(
        private readonly SettingPrinterSyncService $settingPrinterSync,
    ) {}

    public function resolveForOutlet(int $outletId): ?PrinterProfile
    {
        if ($outletId < 1) {
            return null;
        }

        $setting = SettingPrinter::query()
            ->where('outlet_id', $outletId)
            ->whereIn('printer_type', ['cashier', 'receipt'])
            ->orderBy('created_at')
            ->orderBy('id')
            ->first();

        if ($setting === null) {
            return null;
        }

        return $this->resolveProfileForSetting($setting);
    }

    public function resolveRouteForProfile(int $outletId, PrinterProfile $profile): ?PrinterRoute
    {
        return PrinterRoute::query()
            ->where('outlet_id', $outletId)
            ->where('print_type', 'receipt')
            ->where('printer_profile_id', (int) $profile->id)
            ->where('is_active', true)
            ->orderBy('priority')
            ->first();
    }

    public function resolveLegacyReceiptRoute(int $outletId): ?PrinterRoute
    {
        Log::info('print.receipt.fallback_legacy_route', [
            'outlet_id' => $outletId,
        ]);

        return PrinterRoute::query()
            ->where('outlet_id', $outletId)
            ->where('print_type', 'receipt')
            ->where('is_active', true)
            ->orderBy('priority')
            ->first();
    }

    private function resolveProfileForSetting(SettingPrinter $setting): ?PrinterProfile
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
            return null;
        }

        return $profile;
    }
}
