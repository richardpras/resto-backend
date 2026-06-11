<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Modules\Settings\Services\CustomerAppUrlResolver;
use BaconQrCode\Renderer\GDLibRenderer;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class TableQrService
{
    public function __construct(
        private readonly CustomerAppUrlResolver $customerAppUrlResolver,
    ) {}

    /** @return array{tableId:int,tableName:string,qrPublicId:?string,qrUrl:?string,qrImageUrl:?string,qrStatus:string,qrStatusReason:?string} */
    public function buildPayload(RestaurantTable $table): array
    {
        $qrUrl = $this->buildQrUrl($table);
        $status = $this->resolveStatus($table, $qrUrl);

        return [
            'tableId' => (int) $table->id,
            'tableName' => (string) $table->name,
            'qrPublicId' => $table->qr_public_id,
            'qrUrl' => $qrUrl,
            'qrImageUrl' => $qrUrl !== null && $status['status'] === 'ready'
                ? url('/api/v1/tables/'.$table->id.'/qr/image')
                : null,
            'qrStatus' => $status['status'],
            'qrStatusReason' => $status['reason'],
        ];
    }

    public function buildQrUrl(RestaurantTable $table): ?string
    {
        $base = $this->customerAppUrlResolver->resolve();
        if ($base === null || ! $table->qr_public_id) {
            return null;
        }

        return $base.'/qr/'.rawurlencode((string) $table->qr_public_id);
    }

    /** @return array{status:string,reason:?string} */
    public function resolveStatus(RestaurantTable $table, ?string $qrUrl = null): array
    {
        $qrUrl ??= $this->buildQrUrl($table);

        if (! $this->customerAppUrlResolver->isValidConfiguredUrl()) {
            return ['status' => 'missing_url', 'reason' => 'Customer App URL is not configured.'];
        }

        if (! $table->qr_public_id || ! $table->qr_enabled) {
            return ['status' => 'missing_url', 'reason' => 'QR identity is not generated or enabled.'];
        }

        if ($qrUrl === null || ! filter_var($qrUrl, FILTER_VALIDATE_URL)) {
            return ['status' => 'invalid_url', 'reason' => 'QR URL is invalid.'];
        }

        return ['status' => 'ready', 'reason' => null];
    }

    public function renderPngBinary(string $content): string
    {
        if (! extension_loaded('gd')) {
            throw new \RuntimeException('GD extension is required for QR PNG generation.');
        }

        $renderer = new GDLibRenderer(512, 2);
        $writer = new Writer($renderer);

        return $writer->writeString($content);
    }

    public function storePngForTable(RestaurantTable $table): string
    {
        $qrUrl = $this->buildQrUrl($table);
        abort_if($qrUrl === null, 422, 'QR URL is not available for this table.');

        $binary = $this->renderPngBinary($qrUrl);
        $path = 'table-qr/outlet-'.$table->outlet_id.'/table-'.$table->id.'.png';
        Storage::disk('local')->put($path, $binary);

        return $path;
    }

    public function pngFilename(RestaurantTable $table): string
    {
        $slug = Str::slug((string) $table->name, '-');
        if ($slug === '') {
            $slug = 'table-'.$table->id;
        }

        return 'table-'.$slug.'-qr.png';
    }

    /** @return array{restaurantName:string,outletName:string} */
    public function brandingForOutlet(int $outletId): array
    {
        $outlet = Outlet::query()->find($outletId);
        $merchantName = (string) config('app.name', 'Restaurant');

        return [
            'restaurantName' => $merchantName,
            'outletName' => (string) ($outlet?->name ?? 'Outlet'),
        ];
    }

    public function canonicalUrl(RestaurantTable $table): string
    {
        $base = $this->customerAppUrlResolver->resolve() ?? rtrim((string) config('app.url'), '/');
        if ($table->qr_public_id) {
            return $base.'/qr/'.rawurlencode((string) $table->qr_public_id);
        }

        return $base.'/qr-order?outletId='.(int) $table->outlet_id.'&tableId='.(int) $table->id;
    }
}
