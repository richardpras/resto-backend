<?php

namespace App\Modules\Settings\Services;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\Settings\Domain\OutletReceiptSetting;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class OutletReceiptSettingService
{
    /**
     * @return list<array{outletId: string, outletName: string, receiptHeader: string, receiptFooter: string, showLogo: bool, showTaxBreakdown: bool}>
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
     * @return array{outletId: string, outletName: string, receiptHeader: string, receiptFooter: string, showLogo: bool, showTaxBreakdown: bool}
     */
    public function updateForOutlet(string $outletId, array $data): array
    {
        $outlet = Outlet::query()->whereKey($outletId)->first();
        if ($outlet === null) {
            throw (new ModelNotFoundException)->setModel(Outlet::class, [$outletId]);
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
     * @return array{outletId: string, outletName: string, receiptHeader: string, receiptFooter: string, showLogo: bool, showTaxBreakdown: bool}
     */
    private function serializeOutlet(Outlet $outlet): array
    {
        $row = $outlet->receiptSetting;

        return [
            'outletId' => $outlet->id,
            'outletName' => $outlet->name,
            'receiptHeader' => $row?->receipt_header ?? '',
            'receiptFooter' => $row?->receipt_footer ?? '',
            'showLogo' => $row?->show_logo ?? false,
            'showTaxBreakdown' => $row?->show_tax_breakdown ?? false,
        ];
    }
}
