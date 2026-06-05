<?php

namespace App\Modules\Purchase\Services;

use App\Models\Modules\Purchase\Domain\ProcurementMatchConfig;
use App\Models\User;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\Response;

final class ProcurementMatchConfigService
{
    public function __construct(
        private readonly PurchaseScopeService $purchaseScopeService,
    ) {}

    /** @return Collection<int, ProcurementMatchConfig> */
    public function list(?User $actor, mixed $requestedOutletId): Collection
    {
        $query = ProcurementMatchConfig::query()->orderBy('outlet_id');
        $this->purchaseScopeService->applyOutletScope($query, $actor, $requestedOutletId, 'outlet_id');

        return $query->get();
    }

    /** @param array<string,mixed> $data */
    public function create(User $actor, array $data): ProcurementMatchConfig
    {
        $outletId = (int) ($data['outletId'] ?? 0);
        abort_if($outletId < 1, Response::HTTP_UNPROCESSABLE_ENTITY, 'Outlet is required.');
        $this->purchaseScopeService->assertDocumentOutlet($actor, $outletId);

        abort_if(
            ProcurementMatchConfig::query()->where('outlet_id', $outletId)->exists(),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'Match configuration already exists for this outlet.'
        );

        return ProcurementMatchConfig::query()->create($this->mapPayload($data, $outletId));
    }

    /** @param array<string,mixed> $data */
    public function update(ProcurementMatchConfig $config, User $actor, array $data): ProcurementMatchConfig
    {
        $this->purchaseScopeService->assertDocumentOutlet($actor, (int) $config->outlet_id);
        $config->update($this->mapPayload($data, (int) $config->outlet_id, false));

        return $config->fresh();
    }

    /** @return array<string,mixed> */
    private function mapPayload(array $data, int $outletId, bool $includeOutlet = true): array
    {
        $payload = [];

        if ($includeOutlet) {
            $payload['outlet_id'] = $outletId;
        }

        if (array_key_exists('quantityTolerancePercent', $data)) {
            $payload['quantity_tolerance_percent'] = (float) $data['quantityTolerancePercent'];
        }
        if (array_key_exists('priceTolerancePercent', $data)) {
            $payload['price_tolerance_percent'] = (float) $data['priceTolerancePercent'];
        }
        if (array_key_exists('amountTolerancePercent', $data)) {
            $payload['amount_tolerance_percent'] = (float) $data['amountTolerancePercent'];
        }
        if (array_key_exists('autoApproveWithinTolerance', $data)) {
            $payload['auto_approve_within_tolerance'] = (bool) $data['autoApproveWithinTolerance'];
        }
        if (array_key_exists('isActive', $data)) {
            $payload['is_active'] = (bool) $data['isActive'];
        }

        return $payload;
    }
}
