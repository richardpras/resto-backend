<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\User;
use App\Modules\Settings\Support\OutletAccessResolver;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;

class TableQrPdfService
{
    public function __construct(
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly TableQrService $tableQrService,
    ) {}

    /**
     * @param  list<int>  $tableIds
     */
    public function exportForOutlet(User $user, int $outletId, array $tableIds): string
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            throw (new ModelNotFoundException())->setModel(RestaurantTable::class);
        }

        $query = RestaurantTable::query()
            ->where('outlet_id', $outletId)
            ->where('status', 'active')
            ->where('active', true)
            ->where('qr_enabled', true)
            ->whereNotNull('qr_public_id');

        if ($tableIds !== []) {
            $query->whereIn('id', $tableIds);
        }

        /** @var Collection<int, RestaurantTable> $tables */
        $tables = $query->orderBy('name')->get();
        abort_if($tables->isEmpty(), 422, 'No printable QR tables found for this outlet.');

        $branding = $this->tableQrService->brandingForOutlet($outletId);
        $labels = [];
        foreach ($tables as $table) {
            $qrUrl = $this->tableQrService->buildQrUrl($table);
            if ($qrUrl === null) {
                continue;
            }
            $png = base64_encode($this->tableQrService->renderPngBinary($qrUrl));
            $labels[] = [
                'tableName' => (string) $table->name,
                'qrUrl' => $qrUrl,
                'pngBase64' => $png,
            ];
        }

        abort_if($labels === [], 422, 'No valid QR labels could be generated.');

        $html = view('pdf.table-qr-labels', [
            'restaurantName' => $branding['restaurantName'],
            'outletName' => $branding['outletName'],
            'labels' => $labels,
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }
}
