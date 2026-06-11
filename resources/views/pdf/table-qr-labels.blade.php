<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 12mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 11px; color: #111; }
        .grid { width: 100%; border-collapse: collapse; }
        .cell { width: 50%; vertical-align: top; padding: 8mm 4mm; page-break-inside: avoid; }
        .label { border: 1px solid #ccc; border-radius: 6px; padding: 8px; text-align: center; min-height: 95mm; }
        .restaurant { font-size: 13px; font-weight: bold; margin-bottom: 2px; }
        .outlet { font-size: 10px; color: #555; margin-bottom: 6px; }
        .table-name { font-size: 18px; font-weight: bold; margin: 6px 0; }
        .qr { width: 42mm; height: 42mm; margin: 4px auto; }
        .hint { font-size: 9px; color: #444; margin-top: 6px; line-height: 1.35; }
        .url { font-size: 7px; color: #666; word-break: break-all; margin-top: 4px; }
    </style>
</head>
<body>
<table class="grid">
    @foreach($labels as $index => $label)
        @if($index % 2 === 0)
            <tr>
        @endif
        <td class="cell">
            <div class="label">
                <div class="restaurant">{{ $restaurantName }}</div>
                <div class="outlet">{{ $outletName }}</div>
                <div class="table-name">{{ $label['tableName'] }}</div>
                <img class="qr" src="data:image/png;base64,{{ $label['pngBase64'] }}" alt="QR">
                <div class="hint">Scan to order from your table</div>
                <div class="url">{{ $label['qrUrl'] }}</div>
            </div>
        </td>
        @if($index % 2 === 1)
            </tr>
        @endif
    @endforeach
    @if(count($labels) % 2 === 1)
        <td class="cell"></td></tr>
    @endif
</table>
</body>
</html>
