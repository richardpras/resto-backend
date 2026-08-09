<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <style>
        @page { margin: 16mm; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #111; line-height: 1.45; }
        h1 { font-size: 18px; margin: 0 0 4px; }
        .muted { color: #555; font-size: 11px; }
        .header { border-bottom: 1px solid #ddd; padding-bottom: 12px; margin-bottom: 16px; }
        .header-table { width: 100%; border-collapse: collapse; }
        .header-table td { vertical-align: middle; }
        .logo { max-width: 72px; max-height: 72px; }
        .row { margin: 4px 0; }
        .label { display: inline-block; width: 140px; color: #555; }
        .value { font-weight: bold; }
        .section { margin-top: 18px; }
        .section-title { font-size: 13px; font-weight: bold; margin-bottom: 8px; border-bottom: 1px solid #eee; padding-bottom: 4px; }
        table.items { width: 100%; border-collapse: collapse; margin-top: 6px; }
        table.items th, table.items td { border-bottom: 1px solid #eee; padding: 6px 4px; text-align: left; }
        table.items th { font-size: 11px; color: #555; }
        table.items td.num, table.items th.num { text-align: right; }
        .totals { margin-top: 10px; width: 100%; }
        .totals td { padding: 3px 0; }
        .totals td.num { text-align: right; font-weight: bold; }
        .footer { margin-top: 24px; font-size: 10px; color: #666; border-top: 1px solid #eee; padding-top: 10px; }
        .badge { display: inline-block; padding: 2px 8px; border: 1px solid #ccc; border-radius: 999px; font-size: 11px; }
    </style>
</head>
<body>
    <div class="header">
        <table class="header-table">
            <tr>
                @if(!empty($outletLogoDataUri))
                    <td style="width: 84px;">
                        <img class="logo" src="{{ $outletLogoDataUri }}" alt="Logo">
                    </td>
                @endif
                <td>
                    <h1>{{ $outletName }}</h1>
                    @if($outletAddress !== '')
                        <div class="muted">{{ $outletAddress }}</div>
                    @endif
                    @if($outletPhone !== '')
                        <div class="muted">{{ $outletPhone }}</div>
                    @endif
                    <div class="muted" style="margin-top: 8px;">Ringkasan reservasi / Reservation summary</div>
                </td>
            </tr>
        </table>
    </div>

    <div class="row"><span class="label">Kode booking</span> <span class="value">{{ $reservationCode }}</span></div>
    <div class="row"><span class="label">Status</span> <span class="badge">{{ $statusLabel }}</span></div>
    <div class="row"><span class="label">Nama</span> <span class="value">{{ $customerName }}</span></div>
    @if($customerPhone !== '')
        <div class="row"><span class="label">Telepon</span> <span class="value">{{ $customerPhone }}</span></div>
    @endif
    <div class="row"><span class="label">Jumlah tamu</span> <span class="value">{{ $partySize }}</span></div>
    <div class="row"><span class="label">Waktu reservasi</span> <span class="value">{{ $reservationAtFormatted }}</span></div>

    <div class="section">
        <div class="section-title">Deposit</div>
        <div class="row"><span class="label">DP wajib</span> <span class="value">{{ $requiredDepositFormatted }}</span></div>
        @if($approvedDepositFormatted !== null)
            <div class="row"><span class="label">DP disetujui</span> <span class="value">{{ $approvedDepositFormatted }}</span></div>
        @endif
        @if($depositInstructions !== '')
            <div class="muted" style="margin-top: 8px; white-space: pre-wrap;">{{ $depositInstructions }}</div>
        @endif
    </div>

    @if(count($items) > 0)
        <div class="section">
            <div class="section-title">Pre-order</div>
            <table class="items">
                <thead>
                    <tr>
                        <th>Item</th>
                        <th class="num">Qty</th>
                        <th class="num">Harga</th>
                        <th class="num">Subtotal</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($items as $item)
                        <tr>
                            <td>{{ $item['name'] }}</td>
                            <td class="num">{{ $item['qty'] }}</td>
                            <td class="num">{{ $item['priceFormatted'] }}</td>
                            <td class="num">{{ $item['lineFormatted'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <table class="totals">
                <tr>
                    <td>Subtotal</td>
                    <td class="num">{{ $orderSubtotalFormatted }}</td>
                </tr>
                <tr>
                    <td>Pajak</td>
                    <td class="num">{{ $orderTaxFormatted }}</td>
                </tr>
                <tr>
                    <td>Total pre-order</td>
                    <td class="num">{{ $orderTotalFormatted }}</td>
                </tr>
            </table>
        </div>
    @endif

    <div class="footer">
        Simpan dokumen ini sebagai bukti reservasi Anda. Tunjukkan kode booking saat datang.
        <br>
        Generated at {{ $generatedAtFormatted }}
    </div>
</body>
</html>
