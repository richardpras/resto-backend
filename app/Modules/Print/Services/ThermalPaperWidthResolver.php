<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Settings\Domain\SettingPrinter;

class ThermalPaperWidthResolver
{
    public const WIDTH_58MM = 32;

    public const WIDTH_80MM = 42;

    public function resolveWidthChars(?PrinterProfile $profile): int
    {
        $fromMeta = data_get($profile?->meta, 'print.thermalWidthChars');
        if (is_numeric($fromMeta)) {
            return max(20, min(80, (int) $fromMeta));
        }

        return self::WIDTH_58MM;
    }

    public function resolvePaperWidth(?PrinterProfile $profile): string
    {
        $fromMeta = data_get($profile?->meta, 'print.thermalPaperWidth');
        if (is_string($fromMeta)) {
            return $this->normalizePaperWidth($fromMeta);
        }

        return SettingPrinter::PAPER_WIDTH_58MM;
    }

    public function resolveWidthCharsForProfileId(int $profileId): int
    {
        if ($profileId < 1) {
            return self::WIDTH_58MM;
        }

        $profile = PrinterProfile::query()->find($profileId);

        return $this->resolveWidthChars($profile instanceof PrinterProfile ? $profile : null);
    }

    public function resolveFromSettingPrinter(SettingPrinter $setting): int
    {
        return $this->widthCharsForPaperWidth((string) ($setting->thermal_paper_width ?? SettingPrinter::PAPER_WIDTH_58MM));
    }

    public function widthCharsForPaperWidth(string $paperWidth): int
    {
        return match ($this->normalizePaperWidth($paperWidth)) {
            SettingPrinter::PAPER_WIDTH_80MM => self::WIDTH_80MM,
            default => self::WIDTH_58MM,
        };
    }

    public function normalizePaperWidth(?string $width): string
    {
        return in_array($width, [SettingPrinter::PAPER_WIDTH_58MM, SettingPrinter::PAPER_WIDTH_80MM], true)
            ? $width
            : SettingPrinter::PAPER_WIDTH_58MM;
    }

    /**
     * @return array{thermalPaperWidth:string,thermalWidthChars:int}
     */
    public function metaForPaperWidth(string $paperWidth): array
    {
        $normalized = $this->normalizePaperWidth($paperWidth);

        return [
            'thermalPaperWidth' => $normalized,
            'thermalWidthChars' => $this->widthCharsForPaperWidth($normalized),
        ];
    }
}
