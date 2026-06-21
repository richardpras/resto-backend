<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Print\Domain\PrinterProfile;

class PrintBridgePayloadBuilder
{
    /**
     * @return array{transport:string,host:?string,port:?int,devicePath:?string,bluetoothAddress:?string,sharePath:?string,printerName:?string,document:array<string,mixed>}
     */
    public function buildExecutionPayload(PrintJob $job, PrinterProfile $profile): array
    {
        $document = $this->buildDocument($job);

        return array_merge(
            $this->resolveTransport($profile),
            ['document' => $document],
        );
    }

    /**
     * @return array{transport:string,host:?string,port:?int,devicePath:?string,bluetoothAddress:?string,sharePath:?string,printerName:?string}
     */
    private function resolveTransport(PrinterProfile $profile): array
    {
        $connection = strtolower((string) ($profile->connection_type ?: 'lan'));
        $meta = is_array($profile->meta) ? $profile->meta : [];

        if (in_array($connection, ['share', 'shared', 'windows_share', 'windows'], true)) {
            $sharePath = (string) data_get($meta, 'share.path', data_get($meta, 'windows.sharePath', ''));
            $printerName = (string) data_get($meta, 'share.printerName', data_get($meta, 'windows.printerName', $profile->name));

            return [
                'transport' => 'shared',
                'host' => null,
                'port' => null,
                'devicePath' => null,
                'bluetoothAddress' => null,
                'sharePath' => $sharePath !== '' ? $sharePath : null,
                'printerName' => $printerName !== '' ? $printerName : null,
            ];
        }

        if (in_array($connection, ['bluetooth', 'bt'], true)) {
            $devicePath = (string) data_get($meta, 'bluetooth.devicePath', data_get($meta, 'usb.devicePath', ''));

            return [
                'transport' => 'bluetooth',
                'host' => null,
                'port' => null,
                'devicePath' => $devicePath !== '' ? $devicePath : null,
                'bluetoothAddress' => (string) ($profile->bluetooth_address ?: data_get($meta, 'bluetooth.address', '')),
                'sharePath' => null,
                'printerName' => null,
            ];
        }

        if (in_array($connection, ['usb', 'serial'], true)) {
            return [
                'transport' => 'usb',
                'host' => null,
                'port' => null,
                'devicePath' => (string) ($profile->device_identifier ?: data_get($meta, 'usb.devicePath', data_get($meta, 'lan.devicePath', ''))),
                'bluetoothAddress' => null,
                'sharePath' => null,
                'printerName' => null,
            ];
        }

        $host = (string) ($profile->ip_address ?: data_get($meta, 'lan.ip', ''));
        if ($host === '' && $profile->endpoint !== null) {
            $parsed = parse_url((string) $profile->endpoint);
            $host = (string) ($parsed['host'] ?? '');
        }

        return [
            'transport' => 'lan',
            'host' => $host !== '' ? $host : null,
            'port' => (int) data_get($meta, 'lan.port', 9100),
            'devicePath' => null,
            'bluetoothAddress' => null,
            'sharePath' => null,
            'printerName' => null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function buildDocument(PrintJob $job): array
    {
        /** @var array<string,mixed> $snapshot */
        $snapshot = is_array($job->printable_snapshot) ? $job->printable_snapshot : [];

        if (is_string($snapshot['thermalText'] ?? null) && trim((string) $snapshot['thermalText']) !== '') {
            return [
                'lines' => $this->textToLines((string) $snapshot['thermalText']),
                'cut' => true,
            ];
        }

        if ((string) $job->type === 'kitchen') {
            return $this->buildKitchenDocument($snapshot, $job);
        }

        return $this->buildReceiptDocument($snapshot, $job);
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function buildKitchenDocument(array $snapshot, PrintJob $job): array
    {
        $station = (string) data_get($snapshot, 'station', data_get($job->route_snapshot, 'resolved_station', 'kitchen'));
        $lines = [
            ['text' => mb_strtoupper($station).' TICKET', 'bold' => true, 'align' => 'center'],
            ['text' => str_repeat('-', 32), 'align' => 'center'],
        ];

        if ($orderId = data_get($snapshot, 'order_id')) {
            $lines[] = ['text' => 'Order #'.$orderId];
        }

        /** @var list<array<string,mixed>> $items */
        $items = is_array($snapshot['items'] ?? null) ? $snapshot['items'] : [];
        foreach ($items as $item) {
            $qty = number_format((float) ($item['qty'] ?? 0), 0);
            $name = (string) ($item['name'] ?? 'Item');
            $lines[] = ['text' => $qty.' x '.$name, 'bold' => true];
            if (! empty($item['notes'])) {
                $lines[] = ['text' => '  Note: '.$item['notes']];
            }
        }

        $lines[] = ['text' => str_repeat('-', 32)];
        $lines[] = ['text' => now()->format('Y-m-d H:i:s'), 'align' => 'center'];

        return ['lines' => $lines, 'cut' => true];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function buildReceiptDocument(array $snapshot, PrintJob $job): array
    {
        if (is_array($snapshot['receipt_branding'] ?? null)) {
            return $this->buildBrandedReceiptDocument($snapshot);
        }

        $lines = [
            ['text' => 'RECEIPT', 'bold' => true, 'align' => 'center'],
            ['text' => str_repeat('-', 32), 'align' => 'center'],
        ];

        if ($orderId = data_get($snapshot, 'order_id', $job->source_id)) {
            $lines[] = ['text' => 'Order #'.$orderId];
        }
        if ($table = data_get($snapshot, 'table_name')) {
            $lines[] = ['text' => 'Table: '.$table];
        }
        if ($amount = data_get($snapshot, 'amount')) {
            $lines[] = ['text' => 'Total: '.number_format((float) $amount, 0, ',', '.'), 'bold' => true];
        }
        if ($reason = data_get($snapshot, 'reason')) {
            $lines[] = ['text' => (string) $reason, 'align' => 'center'];
        }

        $lines[] = ['text' => now()->format('Y-m-d H:i:s'), 'align' => 'center'];

        return ['lines' => $lines, 'cut' => true];
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function buildBrandedReceiptDocument(array $snapshot): array
    {
        $width = 32;
        $divider = str_repeat('-', $width);
        /** @var array<string,mixed> $branding */
        $branding = $snapshot['receipt_branding'];
        $lines = [];

        $outletName = trim((string) ($branding['outletName'] ?? ''));
        if ($outletName !== '') {
            $lines[] = ['text' => $outletName, 'bold' => true, 'align' => 'center'];
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

        if ($code = data_get($snapshot, 'order_code')) {
            $lines[] = ['text' => 'Order: '.$code];
        }

        $lines[] = ['text' => $divider, 'align' => 'center'];

        /** @var list<array<string,mixed>> $items */
        $items = is_array($snapshot['lines'] ?? null) ? $snapshot['lines'] : [];
        foreach ($items as $row) {
            $qty = number_format((float) ($row['qty'] ?? 0), 0);
            $left = mb_substr((string) ($row['name'] ?? ''), 0, 18).' x'.$qty;
            $amount = number_format((float) ($row['price'] ?? 0) * (float) ($row['qty'] ?? 0), 2, '.', ',');
            $lines[] = ['text' => $left.str_repeat(' ', max(1, $width - mb_strlen($left) - mb_strlen($amount))).$amount];
        }

        $lines[] = ['text' => $divider, 'align' => 'center'];
        $subtotal = number_format((float) ($snapshot['subtotal'] ?? 0), 2, '.', ',');
        $lines[] = ['text' => 'Subtotal'.str_repeat(' ', max(1, $width - 8 - mb_strlen($subtotal))).$subtotal];

        if ((bool) ($branding['showTaxBreakdown'] ?? false)) {
            $tax = number_format((float) ($snapshot['tax'] ?? 0), 2, '.', ',');
            $lines[] = ['text' => 'Tax'.str_repeat(' ', max(1, $width - 3 - mb_strlen($tax))).$tax];
        }

        $total = number_format((float) ($snapshot['total'] ?? data_get($snapshot, 'amount', 0)), 2, '.', ',');
        $lines[] = ['text' => 'TOTAL'.str_repeat(' ', max(1, $width - 5 - mb_strlen($total))).$total, 'bold' => true];
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

        return ['lines' => $lines, 'cut' => true];
    }

    /**
     * @return list<array{text:string,bold?:bool,align?:string}>
     */
    private function textToLines(string $thermalText): array
    {
        $lines = [];
        foreach (preg_split("/\r\n|\n|\r/", $thermalText) ?: [] as $line) {
            $trimmed = rtrim((string) $line);
            if ($trimmed === '') {
                $lines[] = ['text' => ' '];

                continue;
            }
            $lines[] = ['text' => $trimmed];
        }

        return $lines;
    }
}
