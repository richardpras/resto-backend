<?php

namespace App\Modules\Terminals\Services;

use App\Models\Modules\Kitchen\Domain\KitchenTicket;
use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Print\Domain\ReceiptRenderHistory;
use App\Models\Modules\Terminals\Domain\TerminalDevice;
use App\Models\User;
use App\Modules\Kitchen\Http\Requests\UpdateKitchenTicketStatusRequest;
use App\Modules\Kitchen\Services\KitchenTicketService;
use App\Modules\Orders\DTOs\CreateOrderData;
use App\Modules\Orders\Http\Requests\AddOrderPaymentsRequest;
use App\Modules\Orders\Http\Requests\ClosePosSessionRequest;
use App\Modules\Orders\Http\Requests\OpenPosSessionRequest;
use App\Modules\Orders\Http\Requests\StoreOrderRequest;
use App\Modules\Orders\Http\Requests\UpdateOrderRequest;
use App\Modules\Orders\Http\Requests\UpdateOrderStatusRequest;
use App\Modules\Orders\Services\OrderService;
use App\Modules\Orders\Services\PosSessionService;
use App\Modules\Orders\Services\QrOrderApprovalService;
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
            TerminalOperationType::PAYMENT_TRANSACTION_INITIATE => $this->replayPaymentInitiate($user, $outletId, $payload),
            TerminalOperationType::KITCHEN_TICKET_STATUS => $this->replayKitchenTicketStatus($user, $outletId, $payload),
            TerminalOperationType::QR_ORDER_CONFIRM => $this->replayQrConfirm($user, $outletId, $payload),
            TerminalOperationType::QR_ORDER_REJECT => $this->replayQrReject($user, $outletId, $payload),
            TerminalOperationType::PRINT_JOB_RETRY => $this->replayPrintJobRetry($user, $outletId, $payload),
            TerminalOperationType::POS_SESSION_OPEN => $this->replayPosSessionOpen($user, $outletId, $payload),
            TerminalOperationType::POS_SESSION_CLOSE => $this->replayPosSessionClose($user, $outletId, $payload),
            TerminalOperationType::PRINT_DOCUMENT_ENQUEUE => $this->replayPrintDocumentEnqueue($user, $outletId, $payload),
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

        return [
            'entity' => 'order',
            'orderId' => (int) $order->id,
            'updatedAt' => $order->updated_at?->toIso8601String(),
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
        if ($orderId < 1) {
            throw ValidationException::withMessages(['orderId' => ['orderId is required.']]);
        }
        unset($payload['orderId']);
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
        $resolved = $this->qrOrderApprovalService->confirm($user, $requestId, $idempotencyKey);

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
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function replayPosSessionClose(User $user, int $outletId, array $payload): array
    {
        $sessionId = (int) ($payload['sessionId'] ?? $payload['posSessionId'] ?? 0);
        if ($sessionId < 1) {
            throw ValidationException::withMessages(['sessionId' => ['sessionId is required.']]);
        }
        unset($payload['sessionId'], $payload['posSessionId']);
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
