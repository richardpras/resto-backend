<?php

namespace App\Modules\Print\Services;

use Illuminate\Support\Carbon;

class ThermalReceiptLayoutBuilder
{
    public const LAYOUT_VERSION = 'v4';

    /**
     * @param  array<string,mixed>  $snapshot
     * @return list<array{text:string,bold?:bool,align?:string}>
     */
    public function buildCustomerReceipt(array $snapshot, int $width): array
    {
        $width = max(20, min(80, $width));
        $divider = str_repeat('-', $width);
        /** @var array<string,mixed> $branding */
        $branding = is_array($snapshot['receipt_branding'] ?? null) ? $snapshot['receipt_branding'] : [];
        $lines = [];

        $outletName = trim((string) ($branding['outletName'] ?? ''));
        if ($outletName !== '') {
            $lines[] = ['text' => $outletName, 'bold' => true, 'align' => 'center'];
        }

        $isProforma = (bool) ($snapshot['is_proforma'] ?? false);
        if ($isProforma) {
            $lines[] = ['text' => 'BILL', 'bold' => true, 'align' => 'center'];
            $lines[] = ['text' => 'NOT PAID', 'bold' => true, 'align' => 'center'];
        }

        $header = trim((string) ($branding['header'] ?? ''));
        if ($header !== '') {
            foreach (preg_split("/\r\n|\n|\r/", $header) ?: [] as $headerLine) {
                $trimmed = trim((string) $headerLine);
                if ($trimmed !== '') {
                    $lines[] = ['text' => $trimmed, 'align' => 'center'];
                }
            }
        }

        if ($code = ($snapshot['order_code'] ?? null)) {
            $lines[] = ['text' => $this->formatColumns('Order', (string) $code, $width)];
        }

        $lines[] = ['text' => $this->formatColumns(
            'Customer',
            $this->formatCustomerDisplay(
                (string) ($snapshot['customer_display'] ?? $snapshot['customer'] ?? ''),
            ),
            $width,
        )];

        $lines[] = ['text' => $this->formatColumns(
            'Time',
            $this->formatPaidTime($this->resolvePaidAt($snapshot)),
            $width,
        )];

        $lines[] = ['text' => $this->formatColumns(
            'Type',
            $this->formatOrderTypeLabel(
                isset($snapshot['order_type']) ? (string) $snapshot['order_type'] : null,
                isset($snapshot['service_mode']) ? (string) $snapshot['service_mode'] : null,
            ),
            $width,
        )];

        $cashierName = trim((string) ($snapshot['cashier_name'] ?? ''));
        if ($cashierName !== '') {
            $lines[] = ['text' => $this->formatColumns('Cashier', $cashierName, $width)];
        }

        $splitLabel = trim((string) ($snapshot['split_label'] ?? ''));
        if ($splitLabel !== '') {
            $lines[] = ['text' => $this->formatColumns('Split', $splitLabel, $width)];
        }

        if ($num = ($snapshot['fiscal_invoice_number'] ?? null)) {
            $lines[] = ['text' => $this->formatColumns('Invoice', (string) $num, $width)];
        }

        $lines[] = ['text' => $divider, 'align' => 'center'];

        /** @var list<array<string,mixed>> $items */
        $items = is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];
        foreach ($items as $row) {
            $name = mb_substr((string) ($row['name'] ?? ''), 0, $width);
            if ($name !== '') {
                $lines[] = ['text' => $name];
            }

            $qty = (float) ($row['qty'] ?? 0);
            $unitPrice = (float) ($row['price'] ?? 0);
            $lineTotal = $unitPrice * $qty;
            $qtyLabel = number_format($qty, 0).' x '.$this->money($unitPrice);
            $lines[] = ['text' => $this->formatColumns($qtyLabel, $this->money($lineTotal), $width)];
        }

        $lines[] = ['text' => $divider, 'align' => 'center'];
        $lines[] = ['text' => $this->formatColumns('Subtotal', $this->money((float) ($snapshot['subtotal'] ?? 0.0)), $width)];

        /** @var list<array<string,mixed>> $discountLines */
        $discountLines = is_array($snapshot['discount_lines'] ?? null) ? $snapshot['discount_lines'] : [];
        foreach ($discountLines as $discountLine) {
            $label = $this->formatDiscountLabel($discountLine);
            $amount = (float) ($discountLine['amount'] ?? 0.0);
            if ($amount === 0.0) {
                continue;
            }
            $lines[] = ['text' => $this->formatColumns($label, $this->money($amount), $width)];
        }

        if ((bool) ($branding['showTaxBreakdown'] ?? false) && (bool) ($snapshot['apply_tax'] ?? false)) {
            /** @var list<array<string,mixed>> $taxLines */
            $taxLines = is_array($snapshot['tax_lines'] ?? null) ? $snapshot['tax_lines'] : [];
            if ($taxLines !== []) {
                foreach ($taxLines as $taxLine) {
                    $amount = (float) ($taxLine['amount'] ?? 0.0);
                    if ($amount === 0.0) {
                        continue;
                    }
                    $label = trim((string) ($taxLine['label'] ?? 'Tax'));
                    $lines[] = ['text' => $this->formatColumns($label, $this->money($amount), $width)];
                }
            } elseif ((float) ($snapshot['tax'] ?? 0.0) > 0) {
                $lines[] = ['text' => $this->formatColumns('Tax', $this->money((float) ($snapshot['tax'] ?? 0.0)), $width)];
            }
        }

        $lines[] = ['text' => $this->formatColumns(
            'TOTAL',
            $this->money((float) ($snapshot['total'] ?? $snapshot['amount'] ?? 0.0)),
            $width,
        ), 'bold' => true];

        if ($isProforma) {
            $balanceDue = (float) ($snapshot['balance_due'] ?? 0.0);
            $lines[] = ['text' => $this->formatColumns('Balance Due', $this->money($balanceDue), $width), 'bold' => true];
        }

        /** @var list<array<string,mixed>> $payments */
        $payments = is_array($snapshot['payments'] ?? null) ? $snapshot['payments'] : [];
        if (! $isProforma && $payments !== []) {
            $lines[] = ['text' => $divider, 'align' => 'center'];
            foreach ($payments as $payment) {
                $label = trim((string) ($payment['label'] ?? $payment['method'] ?? 'Payment'));
                $lines[] = ['text' => $this->formatColumns($label, $this->money((float) ($payment['amount'] ?? 0.0)), $width)];
            }
        }

        $lines[] = ['text' => $divider, 'align' => 'center'];

        $footer = trim((string) ($branding['footer'] ?? ''));
        if ($footer !== '') {
            foreach (preg_split("/\r\n|\n|\r/", $footer) ?: [] as $footerLine) {
                $trimmed = trim((string) $footerLine);
                if ($trimmed !== '') {
                    $lines[] = ['text' => $trimmed, 'align' => 'center'];
                }
            }
        }

        return array_slice(array_merge($lines, $this->trailingFeedLines()), 0, 256);
    }

    /**
     * @param  array<string,mixed>  $discountLine
     */
    private function formatDiscountLabel(array $discountLine): string
    {
        $name = trim((string) ($discountLine['label'] ?? 'Discount'));

        return match ((string) ($discountLine['type'] ?? '')) {
            'promotion' => 'Promo ('.$name.')',
            'voucher' => 'Voucher ('.$name.')',
            'gift_card' => 'Gift Card ('.$name.')',
            'store_credit' => 'Store Credit ('.$name.')',
            default => $name,
        };
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @param  ?array{width:int,height:int,widthBytes:int,rasterBase64:string}  $thermalRaster
     * @return array{lines:list<array{text:string,bold?:bool,align?:string}>,images?:list<array{align:string,rasterBase64:string,width:int,height:int,widthBytes:int}>,cut:bool}
     */
    public function buildCustomerReceiptDocument(array $snapshot, int $width, ?array $thermalRaster = null): array
    {
        $isBill = (bool) ($snapshot['is_proforma'] ?? false);
        $document = [
            'lines' => $isBill
                ? $this->buildCustomerBill($snapshot, $width)
                : $this->buildCustomerReceipt($snapshot, $width),
            'cut' => true,
        ];

        /** @var array<string,mixed> $branding */
        $branding = is_array($snapshot['receipt_branding'] ?? null) ? $snapshot['receipt_branding'] : [];
        if (($branding['showLogo'] ?? false) && $thermalRaster !== null) {
            $document['images'] = [[
                'align' => 'center',
                'rasterBase64' => (string) $thermalRaster['rasterBase64'],
                'width' => (int) $thermalRaster['width'],
                'height' => (int) $thermalRaster['height'],
                'widthBytes' => (int) $thermalRaster['widthBytes'],
            ]];
        }

        return $document;
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return list<array{text:string,bold?:bool,align?:string}>
     */
    public function buildCustomerBill(array $snapshot, int $width): array
    {
        $snapshot = array_merge($snapshot, ['is_proforma' => true, 'payments' => []]);

        return $this->buildCustomerReceipt($snapshot, $width);
    }

    /**
     * @param  list<array{text:string,bold?:bool,align?:string}>  $structuredLines
     * @return list<string>
     */
    public function toPlainThermalLines(array $structuredLines, int $width): array
    {
        $plain = [];
        foreach ($structuredLines as $line) {
            $text = (string) ($line['text'] ?? '');
            if ($text === '' || $text === ' ') {
                $plain[] = '';

                continue;
            }

            if (($line['align'] ?? null) === 'center') {
                $plain[] = $this->centerLine($text, $width);

                continue;
            }

            $plain[] = $text;
        }

        return $plain;
    }

    /**
     * @return list<array{text:string}>
     */
    public function trailingFeedLines(int $count = 3): array
    {
        $lines = [];
        for ($i = 0; $i < $count; $i++) {
            $lines[] = ['text' => ' '];
        }

        return $lines;
    }

    public function formatCustomerDisplay(?string $name): string
    {
        $trimmed = trim((string) $name);

        return $trimmed !== '' ? $trimmed : 'Guest';
    }

    public function formatOrderTypeLabel(?string $orderType, ?string $serviceMode): string
    {
        $candidates = array_filter([
            trim((string) $orderType),
            trim((string) $serviceMode),
        ]);

        foreach ($candidates as $candidate) {
            $normalized = strtolower(str_replace(['-', '_'], ' ', $candidate));
            if (in_array($normalized, ['dine in', 'dinein'], true)) {
                return 'Dine In';
            }
            if (in_array($normalized, ['take away', 'takeaway'], true)) {
                return 'Take Away';
            }
            if (in_array($normalized, ['online'], true)) {
                return 'Online';
            }
        }

        $primary = trim((string) ($orderType ?: $serviceMode));
        if ($primary === '') {
            return '-';
        }

        return ucwords(str_replace(['_', '-'], ' ', strtolower($primary)));
    }

    public function formatPaidTime(?Carbon $paidAt): string
    {
        return ($paidAt ?? now())->format('d/m/Y H:i');
    }

    /**
     * @param  array<string,mixed>  $snapshot
     */
    public function resolvePaidAt(array $snapshot): ?Carbon
    {
        $raw = $snapshot['paid_at'] ?? null;
        if (is_string($raw) && trim($raw) !== '') {
            return Carbon::parse($raw);
        }

        return null;
    }

    public function formatColumns(string $left, string $right, int $width): string
    {
        $rightLen = mb_strlen($right);
        $leftMax = max(1, $width - $rightLen - 1);
        $left = mb_substr($left, 0, $leftMax);
        $pad = max(1, $width - mb_strlen($left) - mb_strlen($right));

        return $left.str_repeat(' ', $pad).$right;
    }

    public function centerLine(string $text, int $width): string
    {
        $text = mb_substr($text, 0, $width);
        $pad = max(0, (int) floor(($width - mb_strlen($text)) / 2));

        return str_repeat(' ', $pad).$text;
    }

    public function money(float $value): string
    {
        return number_format($value, 2, '.', ',');
    }
}
