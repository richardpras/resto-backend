<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\PosEventLog;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class PosCheckoutIntegrityService
{
    public function __construct(
        private readonly PosAuditLogService $auditLogService,
    ) {}

  /** @param  array<string, mixed>  $context */
    public function recordResumeExistingOrder(Order $order, string $reason, ?User $user, array $context = []): void
    {
        $outletId = (int) ($order->outlet_id ?? 0);
        $payload = [
            'reason' => $reason,
            'orderCode' => (string) $order->code,
            'existingOrderId' => (int) $order->id,
            'existingOrderCode' => (string) $order->code,
            'action' => 'resume_existing_order',
            ...$context,
        ];

        $this->auditLogService->log(
            'payment.resume_existing_order',
            'order',
            (int) $order->id,
            $outletId,
            $user,
            $payload,
        );

        if ($reason === 'qr_order_linked') {
            $this->auditLogService->log(
                'qr_order.resume_existing_bill',
                'order',
                (int) $order->id,
                $outletId,
                $user,
                $payload,
            );
        }

        $this->recordDuplicatePrevented($order, $reason, $user, $context);
    }

    /** @param  array<string, mixed>  $context */
    public function recordDuplicatePrevented(Order $order, string $reason, ?User $user, array $context = []): void
    {
        $this->auditLogService->log(
            'payment.duplicate_order_prevented',
            'order',
            (int) $order->id,
            (int) ($order->outlet_id ?? 0),
            $user,
            ['reason' => $reason, 'orderCode' => (string) $order->code, ...$context],
        );
    }

    public function recordIdempotencyHit(int $orderId, ?int $outletId, ?User $user, ?string $idempotencyKey = null): void
    {
        $this->auditLogService->log(
            'payment.idempotency_hit',
            'order',
            $orderId,
            (int) ($outletId ?? 0),
            $user,
            ['idempotencyKey' => $idempotencyKey],
        );
    }

    public function recordRetryDetected(int $orderId, ?int $outletId, ?User $user, string $source): void
    {
        $this->auditLogService->log(
            'payment.retry_detected',
            'order',
            $orderId,
            (int) ($outletId ?? 0),
            $user,
            ['source' => $source],
        );
    }

    /** @return array<string, int> */
    public function summarize(?int $outletId, int $hours = 24): array
    {
        $since = now()->subHours(max(1, $hours));
        $types = [
            'payment.retry_detected',
            'payment.idempotency_hit',
            'payment.duplicate_order_prevented',
            'payment.resume_existing_order',
            'qr_order.resume_existing_bill',
        ];

        $query = PosEventLog::query()
            ->whereIn('event_type', $types)
            ->where('occurred_at', '>=', $since);

        if ($outletId !== null && $outletId > 0) {
            $query->where('outlet_id', $outletId);
        }

        $counts = $query
            ->select('event_type', DB::raw('COUNT(*) as total'))
            ->groupBy('event_type')
            ->pluck('total', 'event_type');

        return [
            'retries' => (int) ($counts['payment.retry_detected'] ?? 0),
            'idempotencyHits' => (int) ($counts['payment.idempotency_hit'] ?? 0),
            'duplicatePreventionCount' => (int) ($counts['payment.duplicate_order_prevented'] ?? 0),
            'resumeExistingOrderCount' => (int) ($counts['payment.resume_existing_order'] ?? 0),
            'qrResumeCount' => (int) ($counts['qr_order.resume_existing_bill'] ?? 0),
        ];
    }
}
