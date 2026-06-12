<?php

namespace App\Modules\Orders\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QrOrderPosOpenResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->resource;

        return [
            'posSession' => $payload['posSession'] ?? null,
            'loadPayload' => $payload['loadPayload'] ?? null,
            'linkedOrder' => $payload['linkedOrder'] ?? null,
            'request' => isset($payload['request'])
                ? (new QrOrderPreviewResource($payload['request']))->toArray($request)
                : null,
        ];
    }
}
