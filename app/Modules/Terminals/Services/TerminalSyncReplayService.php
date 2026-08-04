<?php

namespace App\Modules\Terminals\Services;

use App\Models\Modules\Kitchen\Domain\KitchenTicket;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Print\Domain\ReceiptRenderHistory;
use App\Models\Modules\Terminals\Domain\TerminalDevice;
use App\Models\User;
use App\Modules\Kitchen\Http\Requests\UpdateKitchenTicketStatusRequest;
use App\Modules\Kitchen\Services\KitchenTicketService;
use App\Modules\Inventory\DTOs\CreateIngredientData;
use App\Modules\Inventory\DTOs\CreateStockMovementData;
use App\Modules\Inventory\Services\DailyStocktakeService;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Members\Services\MemberService;
use App\Modules\Menu\DTOs\UpdateMenuItemData;
use App\Modules\Menu\Services\MenuService;
use App\Modules\Orders\DTOs\CreateOrderData;
use App\Modules\Orders\Http\Requests\AddOrderPaymentsRequest;
use App\Modules\Orders\Http\Requests\ClosePosSessionRequest;
use App\Modules\Orders\Http\Requests\OpenPosSessionRequest;
use App\Modules\Orders\Http\Requests\StoreOrderRequest;
use App\Modules\Orders\Http\Requests\UpdateOrderRequest;
use App\Modules\Orders\Http\Requests\UpdateOrderStatusRequest;
use App\Modules\Orders\Http\Requests\SyncOrderSplitsRequest;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\PosSessionService;
use App\Modules\Orders\Services\QrOrderApprovalService;
use App\Modules\Orders\Services\SplitBillService;
use App\Modules\Payments\Http\Requests\StorePaymentTransactionRequest;
use App\Modules\Payments\Services\PaymentGatewayService;
use App\Modules\Print\Services\PrinterManagementService;
use App\Modules\Print\Services\ReceiptDocumentService;
use App\Modules\Terminals\Support\TerminalOperationType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class TerminalSyncReplayService
{
    public function __construct(
        private readonly OrderService $orderService,
        private readonly PaymentGatewayService $paymentGatewayService,
        private readonly KitchenTicketService $kitchenTicketService,
        private readonly QrOrderApprovalService $qrOrderApprovalService,
        private readonly PosSessionService $posSessionService,
        private readonly PrinterManagementService $printerManagementService,
        private readonly ReceiptDocumentService $receiptDocumentService,
        private readonly SplitBillService $splitBillService,
        private readonly MemberService $memberService,
        private readonly MenuService $menuService,
        private readonly InventoryService $inventoryService,
        private readonly DailyStocktakeService $dailyStocktakeService,
    ) {}

    public function assertWithinReplayWindow(?string $clientOccurredAtIso): void
    {
        if (! is_string($clientOccurredAtIso) || trim($clientOccurredAtIso) === '') {
            return;
        }

        $maxHours = max(1, (int) config('terminals.replay_max_age_hours', 336));
        $occurred = CarbonImmutable::parse($clientOccurredAtIso)->utc();
        if ($occurred->lt(now()->utc()->subHours($maxHours))) {
            throw ValidationException::withMessages([
                'clientOccurredAt' => ['Operation is outside the allowed replay window. Refresh and reconcile.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function execute(User $user, int $outletId, ?TerminalDevice $terminal, string $operationType, array $payload): array
    {
        return match ($operationType) {
            TerminalOperationType::ORDER_CREATE => $this->replayOrderCreate($user, $outletId, $payload),
            TerminalOperationType::ORDER_UPDATE => $this->replayOrderUpdate($user, $outletId, $payload),
            TerminalOperationType::ORDER_UPDATE_STATUS => $this->replayOrderUpdateStatus($user, $outletId, $payload),
            TerminalOperationType::ORDER_ADD_PAYMENTS => $this->replayOrderAddPayments($user, $outletId, $payload),
            TerminalOperationType::ORDER_SPLITS_SYNC => $this->replayOrderSplitsSync($user, $outletId, $payload),
            TerminalOperationType::PAYMENT_TRANSACTION_INITIATE => $this->replayPaymentInitiate($user, $outletId, $payload),
            TerminalOperationType::KITCHEN_TICKET_STATUS => $this->replayKitchenTicketStatus($user, $outletId, $payload),
            TerminalOperationType::QR_ORDER_CONFIRM => $this->replayQrConfirm($user, $outletId, $payload),
            TerminalOperationType::QR_ORDER_REJECT => $this->replayQrReject($user, $outletId, $payload),
            TerminalOperationType::PRINT_JOB_RETRY => $this->replayPrintJobRetry($user, $outletId, $payload),
            TerminalOperationType::POS_SESSION_OPEN => $this->replayPosSessionOpen($user, $outletId, $payload),
            TerminalOperationType::POS_SESSION_CLOSE => $this->replayPosSessionClose($user, $outletId, $payload),
            TerminalOperationType::PRINT_DOCUMENT_ENQUEUE => $this->replayPrintDocumentEnqueue($user, $outletId, $payload),
            TerminalOperationType::MEMBER_QUICK_CREATE => $this->replayMemberCreate($user, $outletId, $payload),
            TerminalOperationType::MEMBER_CREATE => $this->replayMemberCreate($user, $outletId, $payload),
            TerminalOperationType::MENU_ITEM_AVAILABILITY => $this->replayMenuItemAvailability($user, $outletId, $payload),
            TerminalOperationType::INVENTORY_STOCK_MOVEMENT_CREATE => $this->replayInventoryStockMovement($user, $outletId, $payload),
            TerminalOperationType::INVENTORY_ITEM_UPSERT => $this->replayInventoryItemUpsert($user, $outletId, $payload),
            TerminalOperationType::INVENTORY_STOCKTAKE_SAVE_OPENING => $this->replayStocktakeSaveOpening($user, $outletId, $payload),
            TerminalOperationType::INVENTORY_STOCKTAKE_SAVE_CLOSING => $this->replayStocktakeSaveClosing($user, $outletId, $payload),
            TerminalOperationType::INVENTORY_STOCKTAKE_SUBMIT => $this->replayStocktakeSubmit($user, $outletId, $payload),
            default => throw ValidationException::withMessages([
                'operationType' => ['Unsupported sync operation type.'],
            ]),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function replayOrderCreate(User $user, int $outletId, array $payload): array
    {
        if (! isset($payload['outletId'])) {
            $payload['outletId'] = $outletId;
        }
        $this->assertOutletMatches($outletId, (int) $payload['outletId']);
        $validated = $this->validate($payload, (new StoreOrderRequest)->rules());
        $order = $this->orderService->create(CreateOrderData::fromArray($validated), $user);
        $order->load('items');

        return [
            'entity' => 'order',
            'orderId' => (int) $order->id,
            'updatedAt' => $order->updated_at?->toIso8601String(),
            'clientLocalRef' => $validated['clientLocalRef'] ?? null,
            'items' => $order->items->map(fn ($item): array => [
                'clientItemId' => (string) $item->item_id,
                'orderItemId' => (int) $item->id,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replayOrderUpdate(User $user, int $outletId, array $payload): array
    {
        $orderId = (int) ($payload['orderId'] ?? 0);
        if ($orderId < 1) {
            throw ValidationException::withMessages(['orderId' => ['orderId is required.']]);
        }
        unset($payload['orderId']);
        $validated = $this->validate($payload, (new UpdateOrderRequest)->rules());

        $order = $this->orderService->updateOrder($user, $orderId, $validated);

        return [
            'entity' => 'order',
            'orderId' => (int) $order->id,
            'updatedAt' => $order->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replayOrderUpdateStatus(User $user, int $outletId, array $payload): array
    {
        $orderId = (int) ($payload['orderId'] ?? 0);
        if ($orderId < 1) {
            throw ValidationException::withMessages(['orderId' => ['orderId is required.']]);
        }
        unset($payload['orderId']);
        $validated = $this->validate($payload, (new UpdateOrderStatusRequest)->rules());
        $fresh = $this->orderService->updateStatus($user, $orderId, (string) $validated['status']);
        if ($fresh === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
        }

        return [
            'entity' => 'order',
            'orderId' => (int) $fresh->id,
            'updatedAt' => $fresh->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replayOrderAddPayments(User $user, int $outletId, array $payload): array
    {
        $orderId = (int) ($payload['orderId'] ?? 0);
        if ($orderId < 1 && ! empty($payload['localOrderCode'])) {
            $order = Order::query()
                ->where('outlet_id', $outletId)
                ->where('code', (string) $payload['localOrderCode'])
                ->first();
            if ($order !== null) {
                $orderId = (int) $order->id;
            }
        }
        if ($orderId < 1) {
            throw ValidationException::withMessages(['orderId' => ['orderId is required.']]);
        }
        unset($payload['orderId'], $payload['localOrderCode']);
        $validated = $this->validate($payload, (new AddOrderPaymentsRequest)->rules());
        $fresh = $this->orderService->addPayments(
            $user,
            $orderId,
            $validated['payments'],
            $validated['cashAccountCode'] ?? null,
            $validated['revenueAccountCode'] ?? null,
            $validated['idempotencyKey'] ?? null,
            $validated['expectedUpdatedAt'] ?? null,
        );
        if ($fresh === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
        }

        return [
            'entity' => 'order',
            'orderId' => (int) $fresh->id,
            'updatedAt' => $fresh->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replayOrderSplitsSync(User $user, int $outletId, array $payload): array
    {
        $orderId = (int) ($payload['orderId'] ?? 0);
        if ($orderId < 1 && ! empty($payload['localOrderCode'])) {
            $resolved = Order::query()
                ->where('outlet_id', $outletId)
                ->where('code', (string) $payload['localOrderCode'])
                ->first();
            if ($resolved !== null) {
                $orderId = (int) $resolved->id;
            }
        }
        if ($orderId < 1) {
            throw ValidationException::withMessages(['orderId' => ['orderId is required.']]);
        }
        $persons = $payload['persons'] ?? null;
        if (! is_array($persons) || $persons === []) {
            throw ValidationException::withMessages(['persons' => ['persons array is required.']]);
        }

        $order = Order::query()->with('items')->whereKey($orderId)->first();
        if ($order === null) {
            throw (new ModelNotFoundException)->setModel(Order::class, [(string) $orderId]);
        }

        $resolvedPersons = [];
        foreach ($persons as $person) {
            $items = [];
            foreach ($person['items'] ?? [] as $item) {
                $orderItemId = (int) ($item['orderItemId'] ?? 0);
                if ($orderItemId < 1 && ! empty($item['clientItemId'])) {
                    $match = $order->items->firstWhere('item_id', (string) $item['clientItemId']);
                    if ($match !== null) {
                        $orderItemId = (int) $match->id;
                    }
                }
                if ($orderItemId < 1) {
                    throw ValidationException::withMessages(['persons' => ['Unable to resolve order item for split sync.']]);
                }
                $items[] = [
                    'orderItemId' => $orderItemId,
                    'qty' => (float) ($item['qty'] ?? 0),
                    'amount' => (float) ($item['amount'] ?? 0),
                ];
            }
            $resolvedPersons[] = [
                'splitType' => (string) ($person['splitType'] ?? 'mixed'),
                'label' => (string) ($person['label'] ?? 'Split'),
                'items' => $items,
            ];
        }

        $requestPayload = ['persons' => $resolvedPersons];
        if (isset($payload['idempotencyKey']) && is_string($payload['idempotencyKey'])) {
            $requestPayload['idempotencyKey'] = $payload['idempotencyKey'];
        }
        if (isset($payload['expectedUpdatedAt']) && is_string($payload['expectedUpdatedAt'])) {
            $requestPayload['expectedUpdatedAt'] = $payload['expectedUpdatedAt'];
        }
        $validated = $this->validate(
            $requestPayload,
            (new SyncOrderSplitsRequest)->rules(),
        );
        $splits = $this->splitBillService->syncSplits(
            $user,
            $orderId,
            $validated['persons'],
            $validated['idempotencyKey'] ?? null,
            $validated['expectedUpdatedAt'] ?? null,
        );

        return [
            'entity' => 'order_splits',
            'orderId' => $orderId,
            'splits' => $splits->map(fn ($split): array => [
                'id' => (int) $split->id,
                'label' => (string) $split->label,
                'splitType' => (string) $split->split_type,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replayPaymentInitiate(User $user, int $outletId, array $payload): array
    {
        $this->assertOutletMatches($outletId, (int) ($payload['outletId'] ?? 0));
        $validated = $this->validate($payload, (new StorePaymentTransactionRequest)->rules());
        $tx = $this->paymentGatewayService->initiateTransaction($user, $validated);

        return [
            'entity' => 'payment_transaction',
            'transactionId' => (int) $tx->id,
            'status' => (string) $tx->status,
            'updatedAt' => $tx->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replayKitchenTicketStatus(User $user, int $outletId, array $payload): array
    {
        $ticketId = (int) ($payload['kitchenTicketId'] ?? $payload['ticketId'] ?? 0);
        if ($ticketId < 1) {
            throw ValidationException::withMessages(['kitchenTicketId' => ['kitchenTicketId is required.']]);
        }
        unset($payload['kitchenTicketId'], $payload['ticketId']);
        $validated = $this->validate($payload, (new UpdateKitchenTicketStatusRequest)->rules());
        $fresh = $this->kitchenTicketService->updateStatus(
            $user,
            $ticketId,
            (string) $validated['status'],
            $validated['idempotencyKey'] ?? null,
            isset($validated['expectedUpdatedAt']) ? (string) $validated['expectedUpdatedAt'] : null,
        );
        if ($fresh === null) {
            throw (new ModelNotFoundException)->setModel(KitchenTicket::class, [(string) $ticketId]);
        }

        return [
            'entity' => 'kitchen_ticket',
            'kitchenTicketId' => (int) $fresh->id,
            'updatedAt' => $fresh->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replayQrConfirm(User $user, int $outletId, array $payload): array
    {
        $requestId = (int) ($payload['requestId'] ?? 0);
        if ($requestId < 1) {
            throw ValidationException::withMessages(['requestId' => ['requestId is required.']]);
        }
        $idempotencyKey = isset($payload['idempotencyKey']) ? (string) $payload['idempotencyKey'] : null;
        $mode = (string) ($payload['mode'] ?? 'confirm_only');
        $payments = is_array($payload['payments'] ?? null) ? $payload['payments'] : [];
        $resolved = $this->qrOrderApprovalService->confirm($user, $requestId, $mode, $payments, $idempotencyKey);

        return [
            'entity' => 'qr_order_request',
            'requestId' => (int) $resolved->id,
            'orderId' => $resolved->order_id !== null ? (int) $resolved->order_id : null,
            'updatedAt' => $resolved->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replayQrReject(User $user, int $outletId, array $payload): array
    {
        $requestId = (int) ($payload['requestId'] ?? 0);
        if ($requestId < 1) {
            throw ValidationException::withMessages(['requestId' => ['requestId is required.']]);
        }
        $reason = $payload['reason'] ?? null;
        $reason = is_string($reason) ? $reason : null;
        $idempotencyKey = isset($payload['idempotencyKey']) ? (string) $payload['idempotencyKey'] : null;
        $resolved = $this->qrOrderApprovalService->reject($user, $requestId, $reason, $idempotencyKey);

        return [
            'entity' => 'qr_order_request',
            'requestId' => (int) $resolved->id,
            'updatedAt' => $resolved->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replayPrintJobRetry(User $user, int $outletId, array $payload): array
    {
        $printJobId = (int) ($payload['printJobId'] ?? $payload['print_job_id'] ?? 0);
        if ($printJobId < 1) {
            throw ValidationException::withMessages(['printJobId' => ['printJobId is required.']]);
        }
        $payloadOutletId = (int) ($payload['outletId'] ?? 0);
        $this->assertOutletMatches($outletId, $payloadOutletId);
        $job = $this->printerManagementService->retryJob($printJobId, $outletId);

        return [
            'entity' => 'print_job',
            'printJobId' => (int) $job->id,
            'status' => (string) $job->status,
            'updatedAt' => $job->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replayPosSessionOpen(User $user, int $outletId, array $payload): array
    {
        if (! isset($payload['outletId'])) {
            $payload['outletId'] = $outletId;
        }
        $this->assertOutletMatches($outletId, (int) $payload['outletId']);
        $validated = $this->validate($payload, (new OpenPosSessionRequest)->rules());
        $session = $this->posSessionService->open($user, $validated);

        return [
            'entity' => 'pos_session',
            'sessionId' => (int) $session->id,
            'updatedAt' => $session->updated_at?->toIso8601String(),
            'clientLocalRef' => $payload['clientLocalRef'] ?? $validated['clientLocalRef'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replayPosSessionClose(User $user, int $outletId, array $payload): array
    {
        $sessionId = (int) ($payload['sessionId'] ?? $payload['posSessionId'] ?? 0);
        if ($sessionId < 1) {
            $current = $this->posSessionService->current($user, $outletId);
            if ($current === null) {
                throw ValidationException::withMessages(['sessionId' => ['sessionId is required.']]);
            }
            $sessionId = (int) $current->id;
        }
        unset($payload['sessionId'], $payload['posSessionId'], $payload['clientLocalRef']);
        $validated = $this->validate($payload, (new ClosePosSessionRequest)->rules());
        $session = $this->posSessionService->close($user, $sessionId, $validated);

        return [
            'entity' => 'pos_session',
            'sessionId' => (int) $session->id,
            'updatedAt' => $session->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replayPrintDocumentEnqueue(User $user, int $outletId, array $payload): array
    {
        if (! isset($payload['outletId'])) {
            $payload['outletId'] = $outletId;
        }
        $this->assertOutletMatches($outletId, (int) $payload['outletId']);
        $rid = (int) ($payload['renderHistoryId'] ?? 0);
        if ($rid < 1) {
            throw ValidationException::withMessages(['renderHistoryId' => ['renderHistoryId is required.']]);
        }
        $history = ReceiptRenderHistory::query()->find($rid);
        if ($history === null || (int) $history->outlet_id !== $outletId) {
            throw (new ModelNotFoundException)->setModel(ReceiptRenderHistory::class, [(string) $rid]);
        }

        $replayKey = isset($payload['replayKey']) ? (string) $payload['replayKey'] : 'sync-replay';

        $job = $this->receiptDocumentService->enqueueFromDeferredReplay($user, $history, $replayKey);

        return [
            'entity' => 'print_job',
            'printJobId' => (int) $job->id,
            'renderHistoryId' => (int) $history->id,
            'updatedAt' => $job->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function replayMemberCreate(User $user, int $outletId, array $payload): array
    {
        if (! isset($payload['outletId'])) {
            $payload['outletId'] = $outletId;
        }
        $this->assertOutletMatches($outletId, (int) $payload['outletId']);
        $clientLocalRef = isset($payload['clientLocalRef']) ? (string) $payload['clientLocalRef'] : null;
        unset($payload['clientLocalRef']);
        $member = $this->memberService->create($user, $payload);

        return [
            'entity' => 'member',
            'memberId' => (int) $member->id,
            'clientLocalRef' => $clientLocalRef,
            'updatedAt' => $member->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function replayMenuItemAvailability(User $user, int $outletId, array $payload): array
    {
        $menuItemId = (int) ($payload['menuItemId'] ?? $payload['id'] ?? 0);
        if ($menuItemId < 1) {
            throw ValidationException::withMessages(['menuItemId' => ['menuItemId is required.']]);
        }
        if (! array_key_exists('available', $payload)) {
            throw ValidationException::withMessages(['available' => ['available is required.']]);
        }
        $updated = $this->menuService->update($menuItemId, UpdateMenuItemData::fromArray([
            'available' => (bool) $payload['available'],
        ]));
        if ($updated === null) {
            throw (new ModelNotFoundException)->setModel(\App\Models\Modules\Menu\Domain\MenuItem::class, [(string) $menuItemId]);
        }

        return [
            'entity' => 'menu_item',
            'menuItemId' => $menuItemId,
            'available' => (bool) $payload['available'],
            'outletId' => $outletId,
            'updatedAt' => $updated->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function replayInventoryStockMovement(User $user, int $outletId, array $payload): array
    {
        if (! isset($payload['outlet_id'])) {
            $payload['outlet_id'] = $outletId;
        }
        $this->assertOutletMatches($outletId, (int) $payload['outlet_id']);
        $movement = $this->inventoryService->addStockMovement(CreateStockMovementData::fromArray($payload), $user);

        return [
            'entity' => 'stock_movement',
            'stockMovementId' => (int) $movement->id,
            'updatedAt' => $movement->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function replayInventoryItemUpsert(User $user, int $outletId, array $payload): array
    {
        $itemId = (int) ($payload['id'] ?? $payload['inventoryItemId'] ?? 0);
        if ($itemId > 0) {
            $attributes = $payload['attributes'] ?? $payload;
            unset($attributes['id'], $attributes['inventoryItemId'], $attributes['clientLocalRef']);
            /** @var array<string, mixed> $attributes */
            $item = $this->inventoryService->updateIngredient($itemId, $attributes, $user);
            if ($item === null) {
                throw ValidationException::withMessages(['id' => ['Inventory item not found.']]);
            }

            return [
                'entity' => 'inventory_item',
                'inventoryItemId' => (int) $item->id,
                'updatedAt' => $item->updated_at?->toIso8601String(),
            ];
        }

        if (! isset($payload['outletId'])) {
            $payload['outletId'] = $outletId;
        }
        $this->assertOutletMatches($outletId, (int) $payload['outletId']);
        $clientLocalRef = isset($payload['clientLocalRef']) ? (string) $payload['clientLocalRef'] : null;
        unset($payload['clientLocalRef']);
        $item = $this->inventoryService->createIngredient(CreateIngredientData::fromArray($payload), $user);

        return [
            'entity' => 'inventory_item',
            'inventoryItemId' => (int) $item->id,
            'clientLocalRef' => $clientLocalRef,
            'updatedAt' => $item->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function replayStocktakeSaveOpening(User $user, int $outletId, array $payload): array
    {
        $sessionId = $this->resolveStocktakeSessionId($user, $outletId, $payload);
        /** @var list<array{ingredientId: int, openingQty: float}> $lines */
        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        $session = $this->dailyStocktakeService->saveOpening($sessionId, $lines, $user);

        return [
            'entity' => 'stocktake_session',
            'sessionId' => (int) $session->id,
            'updatedAt' => $session->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function replayStocktakeSaveClosing(User $user, int $outletId, array $payload): array
    {
        $sessionId = $this->resolveStocktakeSessionId($user, $outletId, $payload);
        /** @var list<array{ingredientId: int, closingQty: float}> $lines */
        $lines = is_array($payload['lines'] ?? null) ? $payload['lines'] : [];
        $session = $this->dailyStocktakeService->saveClosing($sessionId, $lines, $user);

        return [
            'entity' => 'stocktake_session',
            'sessionId' => (int) $session->id,
            'updatedAt' => $session->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function replayStocktakeSubmit(User $user, int $outletId, array $payload): array
    {
        $sessionId = $this->resolveStocktakeSessionId($user, $outletId, $payload);
        $session = $this->dailyStocktakeService->submitForApproval($sessionId, $user);

        return [
            'entity' => 'stocktake_session',
            'sessionId' => (int) $session->id,
            'status' => (string) $session->status,
            'updatedAt' => $session->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function resolveStocktakeSessionId(User $user, int $outletId, array $payload): int
    {
        $sessionId = (int) ($payload['sessionId'] ?? 0);
        if ($sessionId > 0) {
            return $sessionId;
        }
        $businessDate = isset($payload['businessDate']) ? (string) $payload['businessDate'] : now()->toDateString();
        $session = $this->dailyStocktakeService->getOrCreateSession($outletId, $businessDate, $user);

        return (int) $session->id;
    }

    private function assertOutletMatches(int $batchOutletId, int $payloadOutletId): void
    {
        if ($payloadOutletId < 1) {
            throw ValidationException::withMessages([
                'outletId' => ['outletId is required and must match the sync batch outlet.'],
            ]);
        }
        if ($payloadOutletId !== $batchOutletId) {
            throw ValidationException::withMessages([
                'outletId' => ['Payload outlet must match sync batch outlet.'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    private function validate(array $data, array $rules): array
    {
        $validator = Validator::make($data, $rules);

        /** @phpstan-ignore-next-line */
        $validated = $validator->validate();

        /** @var array<string, mixed> */
        return $validated;
    }
}
