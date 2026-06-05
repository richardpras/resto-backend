<?php

namespace App\Modules\Purchase\Services;

use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\Modules\Purchase\Domain\SupplierPayment;
use App\Models\Modules\Purchase\Domain\SupplierPaymentAllocation;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class SupplierPaymentService
{
    /** @var list<string> */
    private const PAYABLE_INVOICE_STATUSES = ['approved', 'partially_paid'];

    public function __construct(
        private readonly PurchaseScopeService $purchaseScopeService,
        private readonly PurchaseAuditService $purchaseAuditService,
        private readonly ThreeWayMatchService $threeWayMatchService,
    ) {}

    /** @param array<string,mixed> $data */
    public function create(User $actor, array $data): SupplierPayment
    {
        return DB::transaction(function () use ($actor, $data): SupplierPayment {
            abort_if((float) ($data['amount'] ?? 0) <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Payment amount must be greater than zero.');
            abort_if(empty($data['supplierId']), Response::HTTP_UNPROCESSABLE_ENTITY, 'Supplier is required.');

            $supplier = Supplier::query()->findOrFail((int) $data['supplierId']);
            $outletId = isset($data['outletId']) ? (int) $data['outletId'] : null;
            if ($outletId !== null) {
                $this->purchaseScopeService->assertDocumentOutlet($actor, $outletId);
            }

            $allocations = collect($data['allocations'] ?? []);
            $this->validateAllocations($supplier->id, $allocations, null);

            $allocated = (float) $allocations->sum(static fn (array $row): float => (float) $row['allocatedAmount']);
            $amount = (float) $data['amount'];
            abort_if($allocated > $amount, Response::HTTP_UNPROCESSABLE_ENTITY, 'Allocated amount cannot exceed payment amount.');

            $payment = SupplierPayment::query()->create([
                'outlet_id' => $outletId,
                'supplier_id' => $supplier->id,
                'payment_no' => $this->nextNumber(),
                'payment_date' => $data['paymentDate'],
                'payment_method' => $data['paymentMethod'] ?? 'cash',
                'reference_no' => $data['referenceNo'] ?? null,
                'notes' => $data['notes'] ?? null,
                'amount' => $amount,
                'allocated_amount' => $allocated,
                'unallocated_amount' => max(0, $amount - $allocated),
                'status' => 'draft',
            ]);

            $this->syncAllocations($payment, $allocations);

            $fresh = $payment->fresh()->load(['supplier', 'allocations.purchaseInvoice']);
            $this->purchaseAuditService->logSupplierPaymentLifecycle('created', (int) $fresh->id, $outletId, $actor, [
                'paymentNo' => $fresh->payment_no,
                'amount' => $amount,
            ]);

            return $fresh;
        });
    }

    /** @param array<string,mixed> $data */
    public function update(SupplierPayment $payment, User $actor, array $data): SupplierPayment
    {
        $this->assertPaymentOutlet($actor, $payment);
        abort_if($payment->status !== 'draft', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only draft payments can be edited.');

        return DB::transaction(function () use ($payment, $actor, $data): SupplierPayment {
            if (array_key_exists('paymentDate', $data)) {
                $payment->payment_date = $data['paymentDate'];
            }
            if (array_key_exists('paymentMethod', $data)) {
                $payment->payment_method = $data['paymentMethod'];
            }
            if (array_key_exists('referenceNo', $data)) {
                $payment->reference_no = $data['referenceNo'];
            }
            if (array_key_exists('notes', $data)) {
                $payment->notes = $data['notes'];
            }
            if (array_key_exists('amount', $data)) {
                abort_if((float) $data['amount'] <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Payment amount must be greater than zero.');
                $payment->amount = (float) $data['amount'];
            }

            if (array_key_exists('allocations', $data)) {
                $allocations = collect($data['allocations']);
                $this->validateAllocations((int) $payment->supplier_id, $allocations, null);
                $payment->allocations()->delete();
                $this->syncAllocations($payment, $allocations);
            }

            $allocated = (float) $payment->allocations()->sum('allocated_amount');
            abort_if($allocated > (float) $payment->amount, Response::HTTP_UNPROCESSABLE_ENTITY, 'Allocated amount cannot exceed payment amount.');
            $payment->allocated_amount = $allocated;
            $payment->unallocated_amount = max(0, (float) $payment->amount - $allocated);
            $payment->save();

            return $payment->fresh()->load(['supplier', 'allocations.purchaseInvoice']);
        });
    }

    public function approve(SupplierPayment $payment, User $actor): SupplierPayment
    {
        $this->assertPaymentOutlet($actor, $payment);
        abort_if($payment->status !== 'draft', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only draft payments can be approved.');
        abort_if($payment->supplier_id === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Supplier is required.');
        abort_if((float) $payment->amount <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Payment amount must be greater than zero.');

        $payment->load('allocations');
        abort_if($payment->allocations->isEmpty(), Response::HTTP_UNPROCESSABLE_ENTITY, 'Payment allocations are required.');

        return DB::transaction(function () use ($payment, $actor): SupplierPayment {
            $payment->update([
                'status' => 'approved',
                'approved_at' => now(),
                'approved_by' => $actor->id,
            ]);

            $fresh = $payment->fresh()->load(['supplier', 'allocations.purchaseInvoice']);
            $this->purchaseAuditService->logSupplierPaymentLifecycle('approved', (int) $fresh->id, (int) $fresh->outlet_id, $actor, [
                'paymentNo' => $fresh->payment_no,
            ]);

            return $fresh;
        });
    }

    public function post(SupplierPayment $payment, User $actor): SupplierPayment
    {
        $this->assertPaymentOutlet($actor, $payment);
        abort_if($payment->status !== 'approved', Response::HTTP_UNPROCESSABLE_ENTITY, 'Only approved payments can be posted.');

        return DB::transaction(function () use ($payment, $actor): SupplierPayment {
            $payment->load('allocations.purchaseInvoice');
            $this->validateAllocations((int) $payment->supplier_id, $payment->allocations->map(static fn (SupplierPaymentAllocation $a): array => [
                'invoiceId' => $a->purchase_invoice_id,
                'allocatedAmount' => (float) $a->allocated_amount,
            ]), null);

            $payment->update([
                'status' => 'posted',
                'posted_at' => now(),
                'posted_by' => $actor->id,
            ]);

            foreach ($payment->allocations as $allocation) {
                $invoice = $allocation->purchaseInvoice;
                if ($invoice !== null) {
                    $this->threeWayMatchService->validatePayment($invoice, $actor);
                }
                $this->applyAllocationToInvoice($allocation);
                $this->purchaseAuditService->logSupplierPaymentLifecycle('allocation_created', (int) $payment->id, (int) $payment->outlet_id, $actor, [
                    'invoiceId' => $allocation->purchase_invoice_id,
                    'allocatedAmount' => (float) $allocation->allocated_amount,
                ]);
            }

            $fresh = $payment->fresh()->load(['supplier', 'allocations.purchaseInvoice']);
            $this->purchaseAuditService->logSupplierPaymentLifecycle('posted', (int) $fresh->id, (int) $fresh->outlet_id, $actor, [
                'paymentNo' => $fresh->payment_no,
                'allocatedAmount' => (float) $fresh->allocated_amount,
            ]);

            return $fresh;
        });
    }

    public function void(SupplierPayment $payment, User $actor): SupplierPayment
    {
        $this->assertPaymentOutlet($actor, $payment);
        abort_if($payment->status === 'void', Response::HTTP_UNPROCESSABLE_ENTITY, 'Payment is already void.');

        return DB::transaction(function () use ($payment, $actor): SupplierPayment {
            $wasPosted = $payment->status === 'posted';
            $payment->update([
                'status' => 'void',
                'voided_at' => now(),
                'voided_by' => $actor->id,
            ]);

            if ($wasPosted) {
                $payment->load('allocations.purchaseInvoice');
                foreach ($payment->allocations as $allocation) {
                    $this->reverseAllocationFromInvoice($allocation);
                }
            }

            $fresh = $payment->fresh()->load(['supplier', 'allocations.purchaseInvoice']);
            $this->purchaseAuditService->logSupplierPaymentLifecycle('voided', (int) $fresh->id, (int) $fresh->outlet_id, $actor, [
                'paymentNo' => $fresh->payment_no,
            ]);

            return $fresh;
        });
    }

    public function updateInvoiceBalances(PurchaseInvoice $invoice): PurchaseInvoice
    {
        $paid = (float) SupplierPaymentAllocation::query()
            ->where('purchase_invoice_id', $invoice->id)
            ->whereHas('supplierPayment', static fn ($q) => $q->where('status', 'posted'))
            ->sum('allocated_amount');

        $total = (float) ($invoice->total_amount > 0 ? $invoice->total_amount : $invoice->total);
        $outstanding = max(0, $total - $paid);

        $status = $invoice->status;
        if (! in_array($status, ['void'], true)) {
            if ($paid >= $total - 0.0001) {
                $status = 'paid';
            } elseif ($paid > 0) {
                $status = 'partially_paid';
            } elseif (in_array($invoice->status, ['partially_paid', 'paid'], true)) {
                $status = 'approved';
            }
        }

        $invoice->update([
            'paid_amount' => $paid,
            'outstanding_amount' => $outstanding,
            'status' => $status,
        ]);

        return $invoice->refresh();
    }

    /** @param Collection<int, array<string,mixed>> $allocations */
    public function allocateInvoices(SupplierPayment $payment, Collection $allocations): SupplierPayment
    {
        $this->validateAllocations((int) $payment->supplier_id, $allocations, null);
        $payment->allocations()->delete();
        $this->syncAllocations($payment, $allocations);

        $allocated = (float) $payment->allocations()->sum('allocated_amount');
        $payment->update([
            'allocated_amount' => $allocated,
            'unallocated_amount' => max(0, (float) $payment->amount - $allocated),
        ]);

        return $payment->fresh()->load(['supplier', 'allocations.purchaseInvoice']);
    }

    private function applyAllocationToInvoice(SupplierPaymentAllocation $allocation): void
    {
        /** @var PurchaseInvoice $invoice */
        $invoice = PurchaseInvoice::query()->lockForUpdate()->findOrFail((int) $allocation->purchase_invoice_id);
        $this->updateInvoiceBalances($invoice);
    }

    private function reverseAllocationFromInvoice(SupplierPaymentAllocation $allocation): void
    {
        /** @var PurchaseInvoice $invoice */
        $invoice = PurchaseInvoice::query()->lockForUpdate()->findOrFail((int) $allocation->purchase_invoice_id);
        $this->updateInvoiceBalances($invoice);
    }

    /** @param Collection<int, array<string,mixed>> $allocations */
    private function validateAllocations(int $supplierId, Collection $allocations, ?int $excludePaymentId): void
    {
        foreach ($allocations as $row) {
            $invoiceId = (int) ($row['invoiceId'] ?? 0);
            $allocated = (float) ($row['allocatedAmount'] ?? 0);
            abort_if($allocated <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Allocation amount must be greater than zero.');

            /** @var PurchaseInvoice|null $invoice */
            $invoice = PurchaseInvoice::query()->find($invoiceId);
            abort_if($invoice === null, Response::HTTP_NOT_FOUND, 'Invoice not found.');
            abort_if((int) $invoice->supplier_id !== $supplierId, Response::HTTP_UNPROCESSABLE_ENTITY, 'Invoice does not belong to selected supplier.');
            abort_if(! in_array($invoice->status, self::PAYABLE_INVOICE_STATUSES, true), Response::HTTP_UNPROCESSABLE_ENTITY, 'Only approved or partially paid invoices can be allocated.');

            $outstanding = $this->invoiceOutstanding($invoice);
            abort_if($allocated > $outstanding + 0.0001, Response::HTTP_UNPROCESSABLE_ENTITY, 'Allocation exceeds outstanding balance.');
        }
    }

    private function invoiceOutstanding(PurchaseInvoice $invoice): float
    {
        $total = (float) ($invoice->total_amount > 0 ? $invoice->total_amount : $invoice->total);
        $paid = (float) SupplierPaymentAllocation::query()
            ->where('purchase_invoice_id', $invoice->id)
            ->whereHas('supplierPayment', static fn ($q) => $q->where('status', 'posted'))
            ->sum('allocated_amount');

        return max(0, $total - $paid);
    }

    /** @param Collection<int, array<string,mixed>> $allocations */
    private function syncAllocations(SupplierPayment $payment, Collection $allocations): void
    {
        foreach ($allocations as $row) {
            SupplierPaymentAllocation::query()->create([
                'supplier_payment_id' => $payment->id,
                'purchase_invoice_id' => (int) $row['invoiceId'],
                'allocated_amount' => (float) $row['allocatedAmount'],
            ]);
        }
    }

    private function assertPaymentOutlet(User $actor, SupplierPayment $payment): void
    {
        if ($payment->outlet_id !== null) {
            $this->purchaseScopeService->assertDocumentOutlet($actor, (int) $payment->outlet_id);
        }
    }

    private function nextNumber(): string
    {
        $lastId = (int) (SupplierPayment::query()->max('id') ?? 0);

        return 'PAY-'.str_pad((string) ($lastId + 1), 4, '0', STR_PAD_LEFT);
    }
}
