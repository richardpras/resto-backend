<?php

namespace App\Modules\Reservations\Services;

use App\Models\Modules\Reservations\Domain\Reservation;
use App\Models\Modules\Settings\Domain\Outlet;
use Dompdf\Dompdf;
use Dompdf\Options;
use Illuminate\Support\Facades\Storage;

class PublicReservationPdfService
{
    public function __construct(
        private readonly PublicReservationService $publicReservationService,
    ) {}

    public function renderByCode(string $reservationCode): string
    {
        $reservation = $this->publicReservationService->showByCode($reservationCode);

        return $this->render($reservation);
    }

    public function render(Reservation $reservation): string
    {
        $reservation->loadMissing(['outlet.reservationSetting', 'linkedOrder.items']);
        $outlet = $reservation->outlet;
        $order = $reservation->linkedOrder;
        $settings = $outlet?->reservationSetting;

        $formatMoney = static function (?float $amount): string {
            if ($amount === null) {
                return '-';
            }

            return 'Rp '.number_format($amount, 0, ',', '.');
        };

        $items = [];
        if ($order !== null && $order->relationLoaded('items')) {
            foreach ($order->items as $item) {
                $qty = (float) $item->qty;
                $price = (float) $item->price;
                $items[] = [
                    'name' => (string) $item->name,
                    'qty' => rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') ?: '0',
                    'priceFormatted' => $formatMoney($price),
                    'lineFormatted' => $formatMoney($qty * $price),
                ];
            }
        }

        $html = view('pdf.public-reservation-summary', [
            'outletLogoDataUri' => $this->outletLogoDataUri($outlet),
            'outletName' => (string) ($outlet?->name ?? 'Outlet'),
            'outletAddress' => (string) ($outlet?->address ?? ''),
            'outletPhone' => (string) ($outlet?->phone ?? ''),
            'reservationCode' => (string) $reservation->reservation_code,
            'statusLabel' => $this->statusLabel((string) $reservation->status),
            'customerName' => (string) $reservation->customer_name,
            'customerPhone' => (string) ($reservation->customer_phone ?? ''),
            'partySize' => (int) $reservation->party_size,
            'reservationAtFormatted' => $reservation->reservation_at
                ? $reservation->reservation_at->timezone(config('app.timezone'))->format('d M Y H:i')
                : '-',
            'requiredDepositFormatted' => $formatMoney(
                $reservation->required_deposit_amount !== null
                    ? (float) $reservation->required_deposit_amount
                    : null
            ),
            'approvedDepositFormatted' => $reservation->approved_deposit_amount !== null
                ? $formatMoney((float) $reservation->approved_deposit_amount)
                : null,
            'depositInstructions' => (string) ($settings?->deposit_instructions ?? ''),
            'items' => $items,
            'orderSubtotalFormatted' => $formatMoney($order !== null ? (float) $order->subtotal : null),
            'orderTaxFormatted' => $formatMoney($order !== null ? (float) $order->tax : null),
            'orderTotalFormatted' => $formatMoney($order !== null ? (float) $order->total : null),
            'generatedAtFormatted' => now()->timezone(config('app.timezone'))->format('d M Y H:i'),
        ])->render();

        $options = new Options;
        $options->set('isRemoteEnabled', false);
        $options->set('isHtml5ParserEnabled', true);

        $dompdf = new Dompdf($options);
        $dompdf->loadHtml($html, 'UTF-8');
        $dompdf->setPaper('A4', 'portrait');
        $dompdf->render();

        return (string) $dompdf->output();
    }

    private function outletLogoDataUri(?Outlet $outlet): ?string
    {
        if ($outlet === null) {
            return null;
        }

        $disk = Storage::disk('public');
        $candidates = array_values(array_filter([
            $outlet->logo_path_fallback,
            $outlet->logo_path,
        ], static fn ($path): bool => is_string($path) && $path !== ''));

        foreach ($candidates as $relativePath) {
            if (! $disk->exists($relativePath)) {
                continue;
            }

            $binary = $disk->get($relativePath);
            if (! is_string($binary) || $binary === '') {
                continue;
            }

            $ext = strtolower((string) pathinfo($relativePath, PATHINFO_EXTENSION));
            $mime = match ($ext) {
                'png' => 'image/png',
                'jpg', 'jpeg' => 'image/jpeg',
                'webp' => 'image/webp',
                default => null,
            };
            if ($mime === null) {
                continue;
            }

            // Dompdf is unreliable with WebP; convert to JPEG when possible.
            if ($mime === 'image/webp') {
                $jpeg = $this->webpToJpeg($binary);
                if ($jpeg === null) {
                    continue;
                }
                $binary = $jpeg;
                $mime = 'image/jpeg';
            }

            return 'data:'.$mime.';base64,'.base64_encode($binary);
        }

        return null;
    }

    private function webpToJpeg(string $binary): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return null;
        }

        $image = @imagecreatefromstring($binary);
        if ($image === false) {
            return null;
        }

        ob_start();
        $ok = imagejpeg($image, null, 90);
        imagedestroy($image);
        $jpeg = ob_get_clean();

        return $ok && is_string($jpeg) && $jpeg !== '' ? $jpeg : null;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            'pending_deposit' => 'Menunggu DP / Pending deposit',
            'deposit_submitted' => 'Bukti DP dikirim / Deposit proof submitted',
            'confirmed' => 'Dikonfirmasi / Confirmed',
            'checked_in' => 'Check-in / Checked in',
            'seated' => 'Duduk / Seated',
            'completed' => 'Selesai / Completed',
            'cancelled' => 'Dibatalkan / Cancelled',
            'no_show' => 'Tidak datang / No show',
            default => $status,
        };
    }
}
