<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\QrOrderRequest;
use App\Models\Modules\Orders\Domain\QrOrderRequestItem;
use App\Models\Modules\Orders\Domain\RestaurantTable;
use App\Models\User;
use App\Modules\Orders\Events\QrOrderCashierCalled;
use App\Modules\Orders\Events\QrOrderRequestSubmitted;
use App\Modules\Orders\Repositories\QrOrderRequestRepositoryInterface;
use App\Modules\Settings\Services\QrOrderingSettingsService;
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
        private readonly QrOrderCustomerAuditService $customerAuditService,
        private readonly QrOrderNotificationAdapter $notificationAdapter,
        private readonly PosIdempotencyService $idempotencyService,
        private readonly QrOrderingSettingsService $qrOrderingSettingsService,
    ) {}

    public function create(array $payload)
    {
        $appendCode = isset($payload['appendToRequestCode']) ? strtoupper(trim((string) $payload['appendToRequestCode'])) : null;
        if ($appendCode !== null && $appendCode !== '') {
            return $this->appendItems($payload, $appendCode);
        }

        return DB::transaction(function () use ($payload) {
            return $this->idempotencyService->run(
                'qr-orders.create.'.((int) $payload['outletId']).'.'.((int) $payload['tableId']),
                $payload['idempotencyKey'] ?? null,
                $payload,
                function () use ($payload) {
                    $table = $this->resolveActiveTable((int) $payload['outletId'], (int) $payload['tableId']);

                    $request = $this->qrOrderRequestRepository->create([
                        'outlet_id' => (int) $payload['outletId'],
                        'table_id' => (int) $payload['tableId'],
                        'request_code' => $this->generateRequestCode(),
                        'customer_name' => $payload['customerName'] ?? null,
                        'status' => 'pending_cashier_confirmation',
                        'expires_at' => now()->addMinutes((int) ($payload['expiresInMinutes'] ?? 20)),
                    ]);

                    $this->persistItems($request, $payload['items']);

                    $resolved = $this->qrOrderRequestRepository->findScoped((int) $request->id, [(int) $request->outlet_id]);
                    if ($resolved !== null) {
                        $this->auditLogService->log(
                            'qr.request.created',
                            'qr_order_request',
                            (int) $resolved->id,
                            (int) $resolved->outlet_id,
                            null
                        );
                        $this->customerAuditService->log('customer_order.created', $resolved);
                        event(new QrOrderRequestSubmitted(
                            outletId: (int) $resolved->outlet_id,
                            requestId: (int) $resolved->id,
                            requestCode: (string) $resolved->request_code,
                            tableId: (int) $resolved->table_id,
                            customerName: $resolved->customer_name,
                            sequence: (int) $resolved->id,
                            aggregateUpdatedAtIso: $resolved->updated_at?->toIso8601String()
                        ));
                    }

                    return $resolved;
                }
            );
        });
    }

    /** @param array<string, mixed> $payload */
    public function appendItems(array $payload, string $requestCode): QrOrderRequest
    {
        return DB::transaction(function () use ($payload, $requestCode): QrOrderRequest {
            $request = QrOrderRequest::query()
                ->where('request_code', strtoupper(trim($requestCode)))
                ->where('outlet_id', (int) $payload['outletId'])
                ->where('table_id', (int) $payload['tableId'])
                ->lockForUpdate()
                ->first();

            if ($request === null) {
                throw ValidationException::withMessages([
                    'appendToRequestCode' => ['Active QR order not found for append.'],
                ]);
            }

            $request = $this->qrOrderExpiryService->markExpiredIfNeeded($request);
            if (in_array((string) $request->status, ['rejected', 'expired'], true)) {
                throw ValidationException::withMessages([
                    'appendToRequestCode' => ['Cannot append items to a closed QR order.'],
                ]);
            }

            if (in_array((string) $request->status, ['confirmed', 'paid'], true)) {
                throw ValidationException::withMessages([
                    'appendToRequestCode' => ['Order is already confirmed. Ask staff to add items in POS.'],
                ]);
            }

            foreach ($payload['items'] as $item) {
                $menuItemId = (int) $item['menuItemId'];
                $existing = QrOrderRequestItem::query()
                    ->where('qr_order_request_id', $request->id)
                    ->where('menu_item_id', $menuItemId)
                    ->where('notes', $item['notes'] ?? null)
                    ->first();

                if ($existing !== null) {
                    $existing->update([
                        'qty' => (float) $existing->qty + (float) $item['qty'],
                    ]);
                    continue;
                }

                QrOrderRequestItem::query()->create([
                    'qr_order_request_id' => $request->id,
                    'menu_item_id' => $menuItemId,
                    'qty' => (float) $item['qty'],
                    'notes' => $item['notes'] ?? null,
                ]);
            }

            $request->update([
                'expires_at' => now()->addMinutes((int) ($payload['expiresInMinutes'] ?? 20)),
            ]);

            $resolved = $this->qrOrderRequestRepository->findScoped((int) $request->id, [(int) $request->outlet_id]);
            if ($resolved === null) {
                throw ValidationException::withMessages([
                    'appendToRequestCode' => ['Failed to reload QR order after append.'],
                ]);
            }

            $this->customerAuditService->log('customer_order.created', $resolved, null, ['appended' => true]);

            return $resolved;
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

    public function callCashier(int $requestId, int $outletId, int $tableId, ?string $reason = null): QrOrderRequest
    {
        if (! $this->qrOrderingSettingsService->enableCallCashier()) {
            throw ValidationException::withMessages([
                'callCashier' => ['Call Cashier is disabled for this outlet.'],
            ]);
        }

        $normalizedReason = $this->normalizeCallReason($reason);

        return DB::transaction(function () use ($requestId, $outletId, $tableId, $normalizedReason): QrOrderRequest {
            $request = QrOrderRequest::query()
                ->whereKey($requestId)
                ->where('outlet_id', $outletId)
                ->where('table_id', $tableId)
                ->lockForUpdate()
                ->first();

            if ($request === null) {
                throw ValidationException::withMessages([
                    'request' => ['QR order request not found for this table.'],
                ]);
            }

            if (! in_array((string) $request->status, ['pending_cashier_confirmation', 'under_review'], true)) {
                throw ValidationException::withMessages([
                    'request' => ['Only active pre-confirm requests can call the cashier.'],
                ]);
            }

            $calledAt = now();
            $callCount = (int) $request->cashier_call_count + 1;
            $request->update([
                'cashier_called_at' => $calledAt,
                'cashier_call_count' => $callCount,
                'last_cashier_call_reason' => $normalizedReason,
            ]);

            $resolved = QrOrderRequest::query()
                ->whereKey($request->id)
                ->with(['items.menuItem', 'table'])
                ->first();

            $this->auditLogService->log(
                'qr.request.cashier_called',
                'qr_order_request',
                (int) $resolved->id,
                (int) $resolved->outlet_id,
                null,
                ['callCount' => $callCount, 'reason' => $normalizedReason]
            );
            $this->customerAuditService->log('customer_order.call_cashier', $resolved, null, [
                'reason' => $normalizedReason,
                'callCount' => $callCount,
            ]);
            $this->notificationAdapter->customerCallCashier($resolved, $normalizedReason);

            event(new QrOrderCashierCalled(
                outletId: (int) $resolved->outlet_id,
                requestId: (int) $resolved->id,
                requestCode: (string) $resolved->request_code,
                tableId: (int) $resolved->table_id,
                callCount: $callCount,
                calledAtIso: $calledAt->toIso8601String(),
                sequence: (int) $resolved->id,
                aggregateUpdatedAtIso: $resolved->updated_at?->toIso8601String()
            ));

            return $resolved;
        });
    }

    /** @param list<array<string, mixed>> $items */
    private function persistItems(QrOrderRequest $request, array $items): void
    {
        foreach ($items as $item) {
            QrOrderRequestItem::query()->create([
                'qr_order_request_id' => $request->id,
                'menu_item_id' => (int) $item['menuItemId'],
                'qty' => (float) $item['qty'],
                'notes' => $item['notes'] ?? null,
            ]);
        }
    }

    private function resolveActiveTable(int $outletId, int $tableId): RestaurantTable
    {
        $table = RestaurantTable::query()
            ->whereKey($tableId)
            ->where('outlet_id', $outletId)
            ->where('status', 'active')
            ->first();

        if ($table === null) {
            throw ValidationException::withMessages([
                'tableId' => ['Table not found for this outlet or table is inactive.'],
            ]);
        }

        return $table;
    }

    private function normalizeCallReason(?string $reason): string
    {
        $value = strtolower(trim((string) $reason));
        $allowed = ['need_assistance', 'request_bill', 'order_question', 'other'];

        return in_array($value, $allowed, true) ? $value : 'other';
    }

    private function generateRequestCode(): string
    {
        return 'QRO-'.strtoupper((string) str()->random(10));
    }
}
