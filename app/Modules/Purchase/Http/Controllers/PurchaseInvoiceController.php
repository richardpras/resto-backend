<?php

namespace App\Modules\Purchase\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\Modules\Accounting\Domain\Account;
use App\Models\Modules\Purchase\Domain\GoodsReceivingNote;
use App\Models\Modules\Purchase\Domain\PurchaseInvoice;
use App\Models\Modules\Purchase\Domain\PurchaseInvoicePayment;
use App\Models\Modules\Purchase\Domain\PurchaseOrder;
use App\Modules\Accounting\Services\AccountingService;
use App\Modules\Purchase\Http\Requests\StorePurchaseInvoicePaymentRequest;
use App\Modules\Purchase\Http\Requests\StorePurchaseInvoiceRequest;
use App\Modules\Purchase\Http\Requests\UpdatePurchaseInvoiceRequest;
use App\Modules\Purchase\Http\Resources\PurchaseInvoiceResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

class PurchaseInvoiceController extends Controller
{
    public function __construct(
        private readonly AccountingService $accountingService,
    ) {}

    public function index(): JsonResponse
    {
        $rows = PurchaseInvoice::query()
            ->with(['purchaseOrder.items', 'goodsReceivingNote', 'payments'])
            ->latest('id')
            ->get();

        return response()->json([
            'data' => PurchaseInvoiceResource::collection($rows),
        ]);
    }

    public function store(StorePurchaseInvoiceRequest $request): JsonResponse
    {
        $data = $request->validated();

        $invoice = DB::transaction(function () use ($data): PurchaseInvoice {
            /** @var PurchaseOrder|null $po */
            $po = PurchaseOrder::query()->with('items')->find((int) $data['purchaseOrderId']);
            /** @var GoodsReceivingNote|null $gr */
            $gr = GoodsReceivingNote::query()->find((int) $data['goodsReceiptId']);

            abort_if($po === null, Response::HTTP_NOT_FOUND, 'Purchase order not found.');
            abort_if($gr === null, Response::HTTP_NOT_FOUND, 'Goods receipt not found.');
            abort_if((int) $gr->purchase_order_id !== (int) $po->id, Response::HTTP_UNPROCESSABLE_ENTITY, 'Goods receipt must belong to selected purchase order.');

            $subtotal = (float) $po->items->sum(static fn ($item): float => (float) $item->ordered_qty * (float) $item->unit_price);
            $tax = (float) ($data['tax'] ?? 0);
            $total = $subtotal + $tax;

            $created = PurchaseInvoice::query()->create([
                'purchase_order_id' => $po->id,
                'goods_receiving_note_id' => $gr->id,
                'number' => $this->nextNumber(),
                'invoice_date' => $data['date'],
                'total' => $total,
                'tax' => $tax,
                'status' => 'unpaid',
            ]);

            $inventoryOrExpense = Account::query()
                ->where(function ($query): void {
                    $query->where('code', '1300')->orWhere('type', 'expense');
                })
                ->orderByRaw("CASE WHEN code = '1300' THEN 0 ELSE 1 END")
                ->orderBy('id')
                ->first();
            $ap = Account::query()->where('code', '2100')->first();
            abort_if($inventoryOrExpense === null || $ap === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Required accounts not found (1300/expense and 2100).');

            $this->accountingService->createJournal([
                'source_type' => 'purchase_invoice',
                'source_id' => (string) $created->id,
                'journal_date' => $data['date'],
                'description' => 'Purchase invoice '.$created->number,
                'status' => 'posted',
                'lines' => [
                    [
                        'account_id' => $inventoryOrExpense->id,
                        'debit' => $total,
                        'credit' => 0,
                    ],
                    [
                        'account_id' => $ap->id,
                        'debit' => 0,
                        'credit' => $total,
                    ],
                ],
            ]);

            return $created->fresh()->load(['purchaseOrder.items', 'goodsReceivingNote', 'payments']);
        });

        return response()->json([
            'message' => 'Purchase invoice created successfully.',
            'data' => new PurchaseInvoiceResource($invoice),
        ], Response::HTTP_CREATED);
    }

    public function update(UpdatePurchaseInvoiceRequest $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $purchaseInvoice->update([
            'status' => $request->validated()['status'],
        ]);

        return response()->json([
            'message' => 'Purchase invoice updated successfully.',
            'data' => new PurchaseInvoiceResource($purchaseInvoice->fresh()->load(['purchaseOrder.items', 'goodsReceivingNote', 'payments'])),
        ]);
    }

    public function addPayment(StorePurchaseInvoicePaymentRequest $request, PurchaseInvoice $purchaseInvoice): JsonResponse
    {
        $data = $request->validated();

        $updated = DB::transaction(function () use ($data, $purchaseInvoice): PurchaseInvoice {
            /** @var PurchaseInvoice $invoice */
            $invoice = PurchaseInvoice::query()->with('payments')->lockForUpdate()->findOrFail($purchaseInvoice->id);
            $alreadyPaid = (float) $invoice->payments->sum('amount');
            $amount = (float) $data['amount'];
            $remaining = (float) $invoice->total - $alreadyPaid;
            abort_if($remaining <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'Invoice already fully paid.');
            abort_if($amount > $remaining, Response::HTTP_UNPROCESSABLE_ENTITY, 'Payment amount cannot exceed remaining payable.');

            $payment = PurchaseInvoicePayment::query()->create([
                'purchase_invoice_id' => $invoice->id,
                'payment_date' => $data['date'],
                'amount' => $amount,
                'payment_method' => $data['paymentMethod'],
                'reference_no' => $data['referenceNo'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $ap = Account::query()->where('code', '2100')->first();
            $cashOrBank = $data['paymentMethod'] === 'cash'
                ? Account::query()->where('code', '1100')->first()
                : Account::query()->where('name', 'like', '%bank%')->orWhere('code', '1110')->first();
            abort_if($ap === null || $cashOrBank === null, Response::HTTP_UNPROCESSABLE_ENTITY, 'Required AP/cash-bank accounts not found.');

            $this->accountingService->createJournal([
                'source_type' => 'purchase_invoice_payment',
                'source_id' => $payment->id,
                'journal_date' => $data['date'],
                'description' => 'Supplier payment '.$invoice->number,
                'status' => 'posted',
                'lines' => [
                    ['account_id' => $ap->id, 'debit' => $amount, 'credit' => 0],
                    ['account_id' => $cashOrBank->id, 'debit' => 0, 'credit' => $amount],
                ],
            ]);

            $invoice->refresh()->load('payments');
            $nextPaid = (float) $invoice->payments->sum('amount');
            if ($nextPaid <= 0) {
                $invoice->status = 'unpaid';
            } elseif ($nextPaid < (float) $invoice->total) {
                $invoice->status = 'partial';
            } else {
                $invoice->status = 'paid';
            }
            $invoice->save();

            return $invoice->fresh()->load(['purchaseOrder.items', 'goodsReceivingNote', 'payments']);
        });

        return response()->json([
            'message' => 'Supplier payment recorded successfully.',
            'data' => new PurchaseInvoiceResource($updated),
        ], Response::HTTP_CREATED);
    }

    private function nextNumber(): string
    {
        $lastId = (int) (PurchaseInvoice::query()->max('id') ?? 0);
        return 'INV-'.str_pad((string) ($lastId + 1), 4, '0', STR_PAD_LEFT);
    }
}
