<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Print\Domain\ReceiptTemplate;
use Illuminate\Database\Eloquent\Collection;

class ReceiptTemplateAdminService
{
    /**
     * @return Collection<int, ReceiptTemplate>
     */
    public function listMerged(int $outletId): Collection
    {
        $locals = ReceiptTemplate::query()->where('outlet_id', $outletId)->where('is_active', true)->orderBy('kind')->orderByDesc('version')->get();
        if ($locals->isNotEmpty()) {
            return $locals;
        }

        return ReceiptTemplate::query()->where('outlet_id', 0)->where('is_active', true)->orderBy('kind')->orderByDesc('version')->get();
    }

    /**
     * @param  array<string,mixed>  $validated
     */
    public function create(int $outletId, array $validated): ReceiptTemplate
    {
        /** @var ReceiptTemplate $tpl */
        $tpl = ReceiptTemplate::query()->create([
            'outlet_id' => $outletId,
            'kind' => (string) $validated['kind'],
            'code' => (string) ($validated['code'] ?? 'custom'),
            'version' => (int) ($validated['version'] ?? 1),
            'name' => (string) $validated['name'],
            'thermal_width_chars' => (int) ($validated['thermalWidthChars'] ?? 42),
            'printer_profile_id' => isset($validated['printerProfileId']) ? (int) $validated['printerProfileId'] : null,
            'sections' => $validated['sections'] ?? [],
            'defaults' => $validated['defaults'] ?? [],
            'is_active' => (bool) ($validated['isActive'] ?? true),
            'is_default_fallback' => (bool) ($validated['isDefaultFallback'] ?? false),
        ]);

        return $tpl;
    }

    /**
     * @param  array<string,mixed>  $validated
     */
    public function update(ReceiptTemplate $template, array $validated): ReceiptTemplate
    {
        $template->fill([
            'name' => $validated['name'] ?? $template->name,
            'thermal_width_chars' => isset($validated['thermalWidthChars']) ? (int) $validated['thermalWidthChars'] : $template->thermal_width_chars,
            'printer_profile_id' => array_key_exists('printerProfileId', $validated) ? ($validated['printerProfileId'] !== null ? (int) $validated['printerProfileId'] : null) : $template->printer_profile_id,
            'sections' => $validated['sections'] ?? $template->sections,
            'defaults' => $validated['defaults'] ?? $template->defaults,
            'is_active' => isset($validated['isActive']) ? (bool) $validated['isActive'] : $template->is_active,
            'is_default_fallback' => isset($validated['isDefaultFallback']) ? (bool) $validated['isDefaultFallback'] : $template->is_default_fallback,
        ]);
        $template->save();

        return $template;
    }
}
