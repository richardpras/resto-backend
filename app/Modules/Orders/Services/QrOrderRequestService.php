<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\QrOrderRequestItem;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\User;
use App\Modules\Orders\Repositories\QrOrderRequestRepositoryInterface;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QrOrderRequestService
{
    public function __construct(
        private readonly QrOrderRequestRepositoryInterface $qrOrderRequestRepository,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly QrOrderExpiryService $qrOrderExpiryService,
        private readonly PosAuditLogService $auditLogService,
        private readonly PosIdempotencyService $idempotencyService,
    ) {}

    public function create(array $payload)
    {
        return DB::transaction(function () use ($payload) {
            return $this->idempotencyService->run(
                'qr-orders.create.'.((int) $payload['outletId']).'.'.((int) $payload['tableId']),
                $payload['idempotencyKey'] ?? null,
                $payload,
                function () use ($payload) {
                    $table = RestaurantTable::query()
                        ->whereKey((int) $payload['tableId'])
                        ->where('outlet_id', (int) $payload['outletId'])
                        ->where('status', 'active')
                        ->first();

                    if ($table === null) {
                        throw ValidationException::withMessages([
                            'tableId' => ['Table not found for this outlet or table is inactive.'],
                        ]);
                    }

                    $request = $this->qrOrderRequestRepository->create([
                        'outlet_id' => (int) $payload['outletId'],
                        'table_id' => (int) $payload['tableId'],
                        'request_code' => $this->generateRequestCode(),
                        'customer_name' => $payload['customerName'] ?? null,
                        'status' => 'pending_cashier_confirmation',
                        'expires_at' => now()->addMinutes((int) ($payload['expiresInMinutes'] ?? 20)),
                    ]);

                    foreach ($payload['items'] as $item) {
                        QrOrderRequestItem::query()->create([
                            'qr_order_request_id' => $request->id,
                            'menu_item_id' => (int) $item['menuItemId'],
                            'qty' => (float) $item['qty'],
                            'notes' => $item['notes'] ?? null,
                        ]);
                    }

                    $resolved = $this->qrOrderRequestRepository->findScoped((int) $request->id, [(int) $request->outlet_id]);
                    if ($resolved !== null) {
                        $this->auditLogService->log(
                            'qr.request.created',
                            'qr_order_request',
                            (int) $resolved->id,
                            (int) $resolved->outlet_id,
                            null
                        );
                    }

                    return $resolved;
                }
            );
        });
    }

    public function list(User $user, int $perPage, array $filters): LengthAwarePaginator
    {
        $this->qrOrderExpiryService->expirePendingRequests();

        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        $requestedOutletId = isset($filters['outlet_id']) ? (int) $filters['outlet_id'] : null;
        if ($requestedOutletId !== null && $requestedOutletId > 0 && ! in_array($requestedOutletId, $allowed, true)) {
            throw ValidationException::withMessages([
                'outletId' => ['The selected outletId is invalid.'],
            ]);
        }

        return $this->qrOrderRequestRepository->paginateScoped($perPage, $allowed, $filters);
    }

    private function generateRequestCode(): string
    {
        return 'QRO-'.strtoupper((string) str()->random(10));
    }
}
