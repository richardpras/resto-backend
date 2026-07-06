<?php

namespace App\Modules\Settings\Services;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletReceiptSetting;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OutletReceiptSettingService
{
    public function __construct(
        private readonly OutletLogoService $outletLogoService,
    ) {}

    /**
     * @return array{outletId: int, outletName: string, receiptHeader: string, receiptFooter: string, showLogo: bool, showTaxBreakdown: bool, logoUrl?: string, hasLogo: bool, logoVersion: int}
     */
    public function forOutlet(Outlet $outlet): array
    {
        $outlet->loadMissing('receiptSetting');

        return $this->serializeOutlet($outlet);
    }

    /**
     * @return list<array{outletId: int, outletName: string, receiptHeader: string, receiptFooter: string, showLogo: bool, showTaxBreakdown: bool, logoUrl: ?string, hasLogo: bool, logoVersion: int}>
     */
    public function listForResponse(): array
    {
        $outlets = Outlet::query()
            ->orderBy('name')
            ->with('receiptSetting')
            ->get();

        return $outlets->map(fn (Outlet $outlet) => $this->serializeOutlet($outlet))->all();
    }

    /**
     * @param  array{receiptHeader: string, receiptFooter: string, showLogo: bool, showTaxBreakdown: bool}  $data
     * @return array{outletId: int, outletName: string, receiptHeader: string, receiptFooter: string, showLogo: bool, showTaxBreakdown: bool, logoUrl: ?string, hasLogo: bool, logoVersion: int}
     */
    public function updateForOutlet(int $outletId, array $data): array
    {
        $outlet = Outlet::query()->whereKey($outletId)->first();
        if ($outlet === null) {
            throw (new ModelNotFoundException)->setModel(Outlet::class, [(string) $outletId]);
        }

        OutletReceiptSetting::query()->updateOrCreate(
            ['outlet_id' => $outlet->id],
            [
                'receipt_header' => $data['receiptHeader'],
                'receipt_footer' => $data['receiptFooter'],
                'show_logo' => $data['showLogo'],
                'show_tax_breakdown' => $data['showTaxBreakdown'],
            ],
        );

        $outlet->load('receiptSetting');

        return $this->serializeOutlet($outlet);
    }

    /**
     * @return array{outletId: int, outletName: string, receiptHeader: string, receiptFooter: string, showLogo: bool, showTaxBreakdown: bool, logoUrl: ?string, hasLogo: bool, logoVersion: int}
     */
    private function serializeOutlet(Outlet $outlet): array
    {
        $row = $outlet->receiptSetting;
        $payload = [
            'outletId' => (int) $outlet->id,
            'outletName' => $outlet->name,
            'receiptHeader' => $row?->receipt_header ?? '',
            'receiptFooter' => $row?->receipt_footer ?? '',
            'showLogo' => $row?->show_logo ?? false,
            'showTaxBreakdown' => $row?->show_tax_breakdown ?? false,
            'hasLogo' => $this->outletLogoService->hasLogo($outlet),
            'logoVersion' => (int) ($outlet->logo_version ?? 0),
        ];

        $logoUrl = $this->outletLogoService->publicUrl($outlet);
        if ($logoUrl !== null) {
            $payload['logoUrl'] = $logoUrl;
        }

        return $payload;
    }
}
