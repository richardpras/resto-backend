<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Print\Domain\ReceiptTemplate;
use App\Modules\Print\Support\ReceiptDocumentKind;

class ReceiptTemplateResolver
{
    public function resolve(int $outletId, ReceiptDocumentKind $kind, ?int $printerProfileId = null): ReceiptTemplate
    {
        $specific = ReceiptTemplate::query()
            ->where('is_active', true)
            ->where('kind', $kind->value)
            ->when($printerProfileId !== null, fn ($q) => $q->where(function ($nested) use ($printerProfileId): void {
                $nested->whereNull('printer_profile_id')->orWhere('printer_profile_id', $printerProfileId);
            }))
            ->where('outlet_id', $outletId)
            ->orderByDesc('version')
            ->first();

        if ($specific !== null) {
            return $specific;
        }

        $fallback = ReceiptTemplate::query()
            ->where('is_active', true)
            ->where('kind', $kind->value)
            ->where('outlet_id', 0)
            ->where('is_default_fallback', true)
            ->orderByDesc('version')
            ->firstOrFail();

        return $fallback;
    }
}
