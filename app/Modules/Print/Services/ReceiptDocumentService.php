<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Orders\Domain\Order;
use App\Models\Modules\Orders\Domain\OrderSplit;
use App\Models\Modules\Orders\Domain\OrderSplitItem;
use App\Models\Modules\Orders\Domain\PosSession;
use App\Models\Modules\Payments\Domain\PaymentTransaction;
use App\Models\Modules\Print\Domain\PrinterRoute;
use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Print\Domain\ReceiptRenderHistory;
use App\Models\User;
use App\Modules\Print\Support\ReceiptDocumentKind;
use App\Modules\Settings\Support\OutletAccessResolver;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Phase 14 — deterministic receipt / invoice renders, fiscal issuance, PDF artifacts, queue hand-off.
 */
class ReceiptDocumentService
{
    public function __construct(
        private readonly ReceiptTemplateResolver $templateResolver,
        private readonly FiscalInvoiceIssuer $fiscalIssuer,
        private readonly ReceiptPdfRenderer $pdfRenderer,
        private readonly PrinterRoutingService $routing,
        private readonly PrintReprintAuditRecorder $audit,
        private readonly OutletAccessResolver $outletAccessResolver,
        private readonly CashierPrinterResolver $cashierPrinterResolver,
    ) {}

    /**
     * @param  array<string, mixed>  $options  queuePrint?, generatePdf?, issueFiscal?, forceRegenerate?
     */
    public function render(
        User $user,
        int $outletId,
        ReceiptDocumentKind $kind,
        string $sourceType,
        int $sourceId,
        ?int $orderSplitId,
        array $options = [],
    ): ReceiptRenderHistory {
        $this->assertOutlet($user, $outletId);

        $queuePrint = (bool) ($options['queuePrint'] ?? false);
        $generatePdf = (bool) ($options['generatePdf'] ?? false);
        $issueFiscal = (bool) ($options['issueFiscal'] ?? false);
        $force = (bool) ($options['forceRegenerate'] ?? false);

        $template = $this->templateResolver->resolve($outletId, $kind, null);

        $fingerprintSeed = implode('|', [
            (string) $outletId,
            $kind->value,
            $sourceType,
            (string) $sourceId,
            (string) ($orderSplitId ?? 0),
            (string) $template->id,
            (string) $template->version,
        ]);
        $fingerprint = hash('sha256', $force ? ($fingerprintSeed.'|'.uniqid('', true)) : $fingerprintSeed);

        if (! $force) {
            $existing = ReceiptRenderHistory::query()
                ->where('outlet_id', $outletId)
                ->where('render_fingerprint', $fingerprint)
                ->first();
            if ($existing !== null) {
                if ($queuePrint) {
                    $this->queueRenderable($user, $existing, false, 'deterministic-queue');
                }

                return $existing;
            }
        }

        $context = $this->buildSnapshot($user, $outletId, $kind, $sourceType, $sourceId, $orderSplitId);

        $fiscal = null;
        if ($this->requiresFiscal($kind, $issueFiscal)) {
            [$fiscalSourceType, $fiscalSourceId] = $this->resolveFiscalOrigin($sourceType, $sourceId, $orderSplitId);
            $fiscal = $this->fiscalIssuer->issueOrReuse($outletId, $fiscalSourceType, $fiscalSourceId, [
                'kind' => $kind->value,
                'issuedBy' => (int) $user->id,
            ]);
            $context['fiscal_invoice_number'] = $fiscal->invoice_number;
            $context['fiscal_uuid'] = $fiscal->fiscal_uuid;
        }

        $width = max(20, min(80, (int) $template->thermal_width_chars));
        $thermal = $this->buildThermalLines($kind, $context, $width);
        $html = $this->buildHtmlSnapshot($kind, $context);

        /** @var ReceiptRenderHistory $history */
        $history = DB::transaction(function () use ($outletId, $template, $kind, $fingerprint, $sourceType, $sourceId, $orderSplitId, $context, $thermal, $html, $fiscal, $user): ReceiptRenderHistory {
            return ReceiptRenderHistory::query()->create([
                'outlet_id' => $outletId,
                'receipt_template_id' => (int) $template->id,
                'kind' => $kind->value,
                'render_fingerprint' => $fingerprint,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'order_split_id' => $orderSplitId,
                'context_snapshot' => $context,
                'thermal_text' => implode("\n", $thermal),
                'html_snapshot' => $html,
                'pdf_storage_path' => null,
                'fiscal_invoice_id' => $fiscal?->id,
                'issued_by_user_id' => (int) $user->id,
                'reprint_count' => 0,
                'deferred_replay_pending' => false,
                'recovery_meta' => ['engine' => 'phase14:v1'],
            ]);
        });

        if ($generatePdf && $history->html_snapshot !== null) {
            $binary = $this->pdfRenderer->render((string) $history->html_snapshot);
            $path = 'receipt-pdf/'.$outletId.'/rh-'.$history->id.'.pdf';
            Storage::disk('local')->put($path, $binary);
            $history->pdf_storage_path = $path;
            $history->save();
            $this->audit->log($outletId, (int) $history->id, 'pdf_generated', $user, null, null, ['path' => $path]);
        }

        $this->audit->log($outletId, (int) $history->id, 'render', $user, null, null, ['kind' => $kind->value]);

        if ($queuePrint) {
            $this->queueRenderable($user, $history, false, 'initial-queue');
        }

        return $history->fresh()
            ?? ReceiptRenderHistory::query()->findOrFail($history->id);
    }

    public function enqueueReprint(User $user, ReceiptRenderHistory $history, ?string $reason): PrintJob
    {
        $this->assertOutlet($user, (int) $history->outlet_id);

        DB::transaction(function () use ($history): void {
            $history->reprint_count = (int) $history->reprint_count + 1;
            $history->save();
        });

        $this->audit->log((int) $history->outlet_id, (int) $history->id, 'reprint', $user, $reason, null, ['reprintCount' => (int) $history->fresh()?->reprint_count]);

        return $this->queueRenderable($user, $history->fresh() ?? $history, true, $reason ?? 'manual-reprint');
    }

    public function enqueueFromDeferredReplay(User $user, ReceiptRenderHistory $history, string $idempotencySuffix): PrintJob
    {
        $this->assertOutlet($user, (int) $history->outlet_id);
        $history->deferred_replay_pending = false;
        $history->recovery_meta = array_merge($history->recovery_meta ?? [], ['deferredReplay' => now()->toIso8601String()]);
        $history->save();

        return $this->queueRenderable($user, $history, true, $idempotencySuffix);
    }

    private function queueRenderable(User $user, ReceiptRenderHistory $history, bool $isReprint, ?string $idempotencyTail): PrintJob
    {
        $history->loadMissing('fiscalInvoice');
        $kind = ReceiptDocumentKind::from($history->kind);
        $printType = $kind === ReceiptDocumentKind::KitchenChit ? 'kitchen' : 'receipt';
        $outletId = (int) $history->outlet_id;
        $resolvedProfileId = null;
        if ($printType === 'receipt') {
            $profile = $this->cashierPrinterResolver->resolveForOutlet($outletId);
            if ($profile !== null) {
                $resolvedProfileId = (int) $profile->id;
                $route = $this->cashierPrinterResolver->resolveRouteForProfile($outletId, $profile);
            } else {
                $route = $this->cashierPrinterResolver->resolveLegacyReceiptRoute($outletId);
            }
        } else {
            $route = PrinterRoute::query()
                ->where('outlet_id', $outletId)
                ->where('print_type', $printType)
                ->where('is_active', true)
                ->orderBy('priority')
                ->first();
        }

        $suffix = $isReprint
            ? 'requeue-rh'.(string) $history->id.'-r'.((string) ((int) $history->reprint_count)).'-'.substr(sha1((string) $idempotencyTail), 0, 16)
            : 'queue-rh'.(string) $history->id;

        $snapshot = [
            'phase14' => true,
            'renderHistoryId' => (int) $history->id,
            'thermalText' => $history->thermal_text,
            'htmlAvailable' => $history->html_snapshot !== null,
            'pdfAvailable' => $history->pdf_storage_path !== null,
            'invoiceNumber' => $history->fiscalInvoice?->invoice_number ?? data_get($history->context_snapshot, 'fiscal_invoice_number'),
            'deferredReplay' => (bool) $history->deferred_replay_pending,
            'recovery' => ['marker' => 'phase14-queue-v1'],
        ];

        $job = $this->routing->enqueuePrintJob(
            $outletId,
            'receipt_render',
            (int) $history->id,
            $printType,
            $route,
            array_merge((array) $history->context_snapshot, $snapshot),
            $suffix,
            (int) $history->id,
            resolvedProfileId: $resolvedProfileId,
        );

        $this->audit->log((int) $history->outlet_id, (int) $history->id, 'queue', $user, $idempotencyTail, (int) $job->id, ['phase14' => true]);

        return $job;
    }

    private function assertOutlet(User $user, int $outletId): void
    {
        $allowed = $this->outletAccessResolver->allowedOutletIds($user);
        if (! in_array($outletId, $allowed, true)) {
            throw ValidationException::withMessages(['outletId' => ['The selected outlet is invalid.']]);
        }
    }

    private function requiresFiscal(ReceiptDocumentKind $kind, bool $issueFiscal): bool
    {
        if ($kind === ReceiptDocumentKind::FiscalInvoice) {
            return true;
        }

        return $issueFiscal && in_array($kind, [
            ReceiptDocumentKind::CustomerReceipt,
            ReceiptDocumentKind::PaymentReceipt,
            ReceiptDocumentKind::QrReceipt,
        ], true);
    }

    /** @return array{0:string,1:int} */
    private function resolveFiscalOrigin(string $sourceType, int $sourceId, ?int $orderSplitId): array
    {
        if ($orderSplitId !== null) {
            return ['order_split', $orderSplitId];
        }

        if ($sourceType === 'payment_transaction') {
            return ['payment_transaction', $sourceId];
        }

        return ['order', $sourceId];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSnapshot(
        User $user,
        int $outletId,
        ReceiptDocumentKind $kind,
        string $sourceType,
        int $sourceId,
        ?int $orderSplitId,
    ): array {
        return match ($sourceType) {
            'order' => $this->snapshotFromOrder($outletId, $sourceId, $orderSplitId),
            'pos_session' => $this->snapshotFromPosSession($outletId, $sourceId),
            'payment_transaction' => $this->snapshotFromPaymentTx($outletId, $sourceId),
            default => throw ValidationException::withMessages(['sourceType' => ['Unsupported document source type.']]),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshotFromOrder(int $outletId, int $orderId, ?int $splitId): array
    {
        $order = Order::query()->with(['items', 'payments'])->find($orderId);
        if ($order === null || (int) $order->outlet_id !== $outletId) {
            throw ValidationException::withMessages(['sourceId' => ['Order not found for outlet.']]);
        }

        $lines = [];
        foreach ($order->items as $row) {
            $lines[] = [
                'name' => (string) $row->name,
                'qty' => (float) $row->qty,
                'price' => (float) $row->price,
                'notes' => $row->notes,
            ];
        }

        $splitPayload = null;
        if ($splitId !== null) {
            /** @var OrderSplit|null $split */
            $split = OrderSplit::query()->where('order_id', $orderId)->whereKey($splitId)->with(['items.orderItem'])->first();
            if ($split === null) {
                throw ValidationException::withMessages(['orderSplitId' => ['Split not found for order.']]);
            }
            $splitPayload = [
                'label' => (string) $split->label,
                'items' => $split->items->map(function (OrderSplitItem $i): array {
                    $label = $i->orderItem !== null ? (string) $i->orderItem->name : 'Line';

                    return ['qty' => (float) $i->qty, 'amount' => (float) $i->amount, 'label' => $label];
                })->values()->all(),
            ];
        }

        return [
            'order_code' => (string) $order->code,
            'order_channel' => (string) ($order->order_channel ?? ''),
            'table' => $order->table_name,
            'customer' => $order->customer_name,
            'subtotal' => (float) $order->subtotal,
            'tax' => (float) $order->tax,
            'total' => (float) $order->total,
            'paid_total' => (float) $order->paid_total,
            'payments' => $order->payments->map(fn ($p): array => [
                'method' => (string) $p->method,
                'amount' => (float) $p->amount,
            ])->values()->all(),
            'lines' => $lines,
            'split' => $splitPayload,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshotFromPosSession(int $outletId, int $sessionId): array
    {
        $session = PosSession::query()->find($sessionId);
        if ($session === null || (int) $session->outlet_id !== $outletId) {
            throw ValidationException::withMessages(['sourceId' => ['POS session not found for outlet.']]);
        }

        return [
            'session_id' => (int) $session->id,
            'status' => (string) $session->status,
            'opening_cash' => (float) $session->opening_cash,
            'closing_cash' => $session->closing_cash !== null ? (float) $session->closing_cash : null,
            'variance' => $session->cash_variance !== null ? (float) $session->cash_variance : null,
            'opened_at' => $session->opened_at?->toIso8601String(),
            'closed_at' => $session->closed_at?->toIso8601String(),
            'cashier_notes' => $session->notes,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function snapshotFromPaymentTx(int $outletId, int $txId): array
    {
        $tx = PaymentTransaction::query()->with('order')->find($txId);
        if ($tx === null || (int) $tx->outlet_id !== $outletId) {
            throw ValidationException::withMessages(['sourceId' => ['Payment transaction not found for outlet.']]);
        }

        return [
            'transaction_id' => (int) $tx->id,
            'provider' => (string) $tx->provider,
            'status' => (string) $tx->status,
            'amount' => (float) $tx->amount,
            'currency' => (string) $tx->currency,
            'external_reference' => (string) $tx->external_reference,
            'order_code' => $tx->order?->code,
        ];
    }

    /**
     * @param  array<string,mixed>  $context
     * @return list<string>
     */
    private function buildThermalLines(ReceiptDocumentKind $kind, array $context, int $width): array
    {
        $divider = str_repeat('-', $width);
        $lines = [];
        $title = match ($kind) {
            ReceiptDocumentKind::KitchenChit => 'KITCHEN TICKET',
            ReceiptDocumentKind::CashierCloseSummary => 'CLOSE SUMMARY',
            ReceiptDocumentKind::PaymentReceipt => 'PAYMENT',
            ReceiptDocumentKind::FiscalInvoice, ReceiptDocumentKind::CustomerReceipt => 'RECEIPT',
            ReceiptDocumentKind::QrReceipt => 'QR RECEIPT',
        };
        $lines[] = mb_strtoupper($title);
        $lines[] = $divider;
        if ($code = ($context['order_code'] ?? null)) {
            $lines[] = 'Order: '.$code;
        }
        if ($num = ($context['fiscal_invoice_number'] ?? null)) {
            $lines[] = 'Invoice: '.$num;
        }
        if ($kind === ReceiptDocumentKind::KitchenChit) {
            foreach ($context['lines'] ?? [] as $row) {
                $qty = number_format((float) ($row['qty'] ?? 0), 2);
                $name = mb_substr((string) ($row['name'] ?? ''), 0, max(12, $width - 12));
                $lines[] = $qty.' × '.$name;
                if (! empty($row['notes'])) {
                    $lines[] = ' Notes: '.$row['notes'];
                }
            }
        } elseif (in_array($kind, [ReceiptDocumentKind::CashierCloseSummary], true)) {
            $lines[] = 'Session #'.((string) ($context['session_id'] ?? '?'));
            $lines[] = 'Open: '.$this->money((float) ($context['opening_cash'] ?? 0.0));
            if (isset($context['closing_cash'])) {
                $lines[] = 'Close: '.$this->money((float) $context['closing_cash']);
                $lines[] = 'Variance: '.$this->money((float) ($context['variance'] ?? 0.0));
            }
        } elseif ($kind === ReceiptDocumentKind::PaymentReceipt) {
            $lines[] = 'Tx #'.((string) ($context['transaction_id'] ?? '?'));
            $lines[] = 'Amount: '.$this->money((float) ($context['amount'] ?? 0.0)).' '.$this->sanitize((string) ($context['currency'] ?? ''));
            $lines[] = 'Provider: '.$this->sanitize((string) ($context['provider'] ?? ''));
        } else {
            foreach ($context['lines'] ?? [] as $row) {
                $lines[] = (($row['name'] ?? '')).'  ×'.number_format((float) ($row['qty'] ?? 0), 2).'  '.$this->money((float) ($row['price'] ?? 0) * (float) ($row['qty'] ?? 0));
            }
            if (! empty($context['split'])) {
                $lines[] = '-- SPLIT '.$this->sanitize((string) $context['split']['label']).' --';
                foreach ($context['split']['items'] ?? [] as $chunk) {
                    $lines[] = ($chunk['label'] ?? '').' × '.$this->sanitize((string) ($chunk['qty'] ?? ''));
                }
            }
            $lines[] = 'Sub '.$this->money((float) ($context['subtotal'] ?? 0.0));
            $lines[] = 'Tax '.$this->money((float) ($context['tax'] ?? 0.0));
            $lines[] = 'TOTAL '.$this->money((float) ($context['total'] ?? 0.0));
        }

        return array_slice($lines, 0, 256);
    }

    /**
     * @param  array<string,mixed>  $context
     */
    private function buildHtmlSnapshot(ReceiptDocumentKind $kind, array $context): string
    {
        $rows = htmlspecialchars((string) json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), ENT_QUOTES, 'UTF-8');
        $snapshot = htmlspecialchars((string) json_encode($context['lines'] ?? $context['payments'] ?? $context ?? [], JSON_PRETTY_PRINT), ENT_QUOTES, 'UTF-8');

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8"/><style>
body{font-family:DejaVu Sans,Arial,sans-serif;font-size:12px;padding:12px;color:#222}
header{font-weight:bold;font-size:16px;margin-bottom:8px}
table{width:100%;border-collapse:collapse;border:1px solid #bbb}
td,th{border:1px solid #bbb;padding:4px;font-size:11px;text-align:left}
pre{background:#f6f8fa;padding:8px;font-size:10px;border:1px solid #dfe2e5;white-space:pre-wrap}
.section{margin-top:12px;font-weight:bold;font-size:12px}
</style></head><body>
<header>Fiscal {$this->sanitize($kind->value)}</header>
<p>Structured snapshot</p>
<pre>{$snapshot}</pre>
<div class="section">Full context (debug-ready)</div>
<pre>{$rows}</pre>
</body></html>
HTML;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }

    private function sanitize(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }

    public function pdfStreamPath(ReceiptRenderHistory $history): ?string
    {
        $path = $history->pdf_storage_path;
        if ($path === null) {
            return null;
        }
        $abs = Storage::disk('local')->path($path);

        return is_readable($abs) ? $abs : null;
    }
}
