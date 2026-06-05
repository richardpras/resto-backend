<?php

namespace App\Modules\Purchase\Services;

use App\Models\User;
use App\Modules\Orders\Services\PosAuditLogService;

final class PurchaseAuditService
{
    public function __construct(
        private readonly PosAuditLogService $auditLogService,
    ) {}

    /** @param array<string,mixed>|null $payload */
    public function logPurchaseOrder(string $action, int $purchaseOrderId, ?int $outletId, ?User $actor = null, ?array $payload = null): void
    {
        $this->auditLogService->log(
            'purchase_order.'.$action,
            'purchase_order',
            $purchaseOrderId,
            $outletId,
            $actor,
            $payload
        );
    }

    /** @param array<string,mixed>|null $payload */
    public function logGoodsReceipt(string $action, int $grnId, ?int $outletId, ?User $actor = null, ?array $payload = null): void
    {
        $this->auditLogService->log(
            'goods_receipt.'.$action,
            'goods_receiving_note',
            $grnId,
            $outletId,
            $actor,
            $payload
        );
    }

    /** @param array<string,mixed>|null $payload */
    public function logGoodsReceiptLifecycle(string $action, int $grnId, ?int $outletId, ?User $actor = null, ?array $payload = null): void
    {
        $this->auditLogService->log(
            'goods_receipt_'.$action,
            'goods_receiving_note',
            $grnId,
            $outletId,
            $actor,
            $payload
        );
    }

    /** @param array<string,mixed>|null $payload */
    public function logPurchaseInvoice(string $action, int $invoiceId, ?int $outletId, ?User $actor = null, ?array $payload = null): void
    {
        $this->auditLogService->log(
            'purchase_invoice.'.$action,
            'purchase_invoice',
            $invoiceId,
            $outletId,
            $actor,
            $payload
        );
    }

    /** @param array<string,mixed>|null $payload */
    public function logPurchaseInvoiceLifecycle(string $action, int $invoiceId, ?int $outletId, ?User $actor = null, ?array $payload = null): void
    {
        $this->auditLogService->log(
            'purchase_invoice_'.$action,
            'purchase_invoice',
            $invoiceId,
            $outletId,
            $actor,
            $payload
        );
    }

    /** @param array<string,mixed>|null $payload */
    public function logPurchaseOrderLifecycle(string $action, int $purchaseOrderId, ?int $outletId, ?User $actor = null, ?array $payload = null): void
    {
        $this->auditLogService->log(
            'purchase_order_'.$action,
            'purchase_order',
            $purchaseOrderId,
            $outletId,
            $actor,
            $payload
        );
    }

    /** @param array<string,mixed>|null $payload */
    public function logSupplierPaymentLifecycle(string $action, int $paymentId, ?int $outletId, ?User $actor = null, ?array $payload = null): void
    {
        $this->auditLogService->log(
            'supplier_payment_'.$action,
            'supplier_payment',
            $paymentId,
            $outletId,
            $actor,
            $payload
        );
    }

    /** @param array<string,mixed>|null $payload */
    public function logPurchaseRequest(string $action, int $purchaseRequestId, ?int $outletId, ?User $actor = null, ?array $payload = null): void
    {
        $this->auditLogService->log(
            'purchase_request_'.$action,
            'purchase_request',
            $purchaseRequestId,
            $outletId,
            $actor,
            $payload
        );
    }

    /** @param array<string,mixed>|null $payload */
    public function logProcurementMatch(string $action, int $invoiceId, ?int $outletId, ?User $actor = null, ?array $payload = null): void
    {
        $event = match ($action) {
            'created' => 'procurement_match_created',
            'revalidated' => 'procurement_match_revalidated',
            'failed' => 'procurement_match_failed',
            'invoice_approval_blocked' => 'invoice_approval_blocked',
            'payment_blocked' => 'payment_blocked_by_match',
            default => 'procurement_match_'.$action,
        };

        $this->auditLogService->log(
            $event,
            'purchase_invoice',
            $invoiceId,
            $outletId,
            $actor,
            $payload
        );
    }
}
