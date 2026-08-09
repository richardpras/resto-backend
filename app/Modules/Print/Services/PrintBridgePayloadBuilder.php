<?php

namespace App\Modules\Print\Services;

use App\Models\Modules\Print\Domain\PrintJob;
use App\Models\Modules\Print\Domain\PrinterProfile;

class PrintBridgePayloadBuilder
{
    public function __construct(
        private readonly ThermalPaperWidthResolver $thermalPaperWidthResolver,
        private readonly ThermalReceiptLayoutBuilder $thermalReceiptLayout,
    ) {}

    /**
     * @return array{transport:string,host:?string,port:?int,devicePath:?string,bluetoothAddress:?string,sharePath:?string,printerName:?string,document:array<string,mixed>}
     */
    public function buildExecutionPayload(PrintJob $job, PrinterProfile $profile): array
    {
        $width = $this->thermalPaperWidthResolver->resolveWidthChars($profile);
        $document = $this->buildDocument($job, $width);
        $document['cut'] = $this->resolveAutoCut($profile);

        return array_merge(
            $this->resolveTransport($profile),
            ['document' => $document],
        );
    }

    private function resolveAutoCut(PrinterProfile $profile): bool
    {
        $meta = is_array($profile->meta) ? $profile->meta : [];
        $print = is_array($meta['print'] ?? null) ? $meta['print'] : [];
        if (array_key_exists('autoCut', $print)) {
            return (bool) $print['autoCut'];
        }

        // Default on — printers without a cutter ignore GS V cut commands.
        return true;
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
    private function buildDocument(PrintJob $job, int $width): array
    {
        /** @var array<string,mixed> $snapshot */
        $snapshot = is_array($job->printable_snapshot) ? $job->printable_snapshot : [];

        if (is_array($snapshot['thermalDocument'] ?? null) && is_array($snapshot['thermalDocument']['lines'] ?? null)) {
            /** @var array<string,mixed> $document */
            $document = $snapshot['thermalDocument'];

            return array_merge(['cut' => true], $document);
        }

        if (is_string($snapshot['thermalText'] ?? null) && trim((string) $snapshot['thermalText']) !== '') {
            return [
                'lines' => $this->textToLines((string) $snapshot['thermalText']),
                'cut' => true,
            ];
        }

        if ((string) $job->type === 'kitchen') {
            return $this->buildKitchenDocument($snapshot, $job, $width);
        }

        return $this->buildReceiptDocument($snapshot, $job, $width);
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function buildKitchenDocument(array $snapshot, PrintJob $job, int $width): array
    {
        $stationFallback = (string) (
            data_get($job->route_snapshot, 'menu_category_name')
            ?: data_get($job->route_snapshot, 'resolved_station')
            ?: 'kitchen'
        );

        return $this->thermalReceiptLayout->buildKitchenTicketDocument($snapshot, $width, $stationFallback);
    }

    /**
     * @param  array<string,mixed>  $snapshot
     * @return array<string,mixed>
     */
    private function buildReceiptDocument(array $snapshot, PrintJob $job, int $width): array
    {
        if (is_array($snapshot['receipt_branding'] ?? null)) {
            return $this->buildBrandedReceiptDocument($snapshot, $width);
        }

        $divider = str_repeat('-', $width);
        $lines = [
            ['text' => 'RECEIPT', 'bold' => true, 'align' => 'center'],
            ['text' => $divider, 'align' => 'center'],
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
    private function buildBrandedReceiptDocument(array $snapshot, int $width): array
    {
        return [
            'lines' => $this->thermalReceiptLayout->buildCustomerReceipt($snapshot, $width),
            'cut' => true,
        ];
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
