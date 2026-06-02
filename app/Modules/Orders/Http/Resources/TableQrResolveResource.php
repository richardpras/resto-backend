<?php

namespace App\Modules\Orders\Http\Resources;

use App\Modules\Orders\Services\TableQrManagementService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Modules\Orders\Domain\RestaurantTable */
class TableQrResolveResource extends JsonResource
{
    public function __construct($resource, private readonly TableQrManagementService $tableQrManagementService)
    {
        parent::__construct($resource);
    }

    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'outletId' => (int) $this->outlet_id,
            'tableId' => (int) $this->id,
            'tableName' => (string) $this->name,
            'qrPublicId' => $this->qr_public_id,
            'qrEnabled' => (bool) $this->qr_enabled,
            'canonicalUrl' => $this->tableQrManagementService->canonicalUrl($this->resource),
        ];
    }
}
