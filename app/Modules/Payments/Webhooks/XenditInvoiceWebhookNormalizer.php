<?php

namespace App\Modules\Payments\Webhooks;

use App\Modules\Payments\Contracts\PaymentWebhookHandlerContract;
use Illuminate\Support\Carbon;

/**
 * Normalizes Xendit Invoice + QRIS callback JSON into internal webhook payload shape.
 *
 * @see https://docs.xendit.co/docs/invoice-callbacks
 */
final class XenditInvoiceWebhookNormalizer implements PaymentWebhookHandlerContract
{
    /**
     * @param  array<string, mixed>  $decodedJson
     * @return array<string, mixed>
     */
    public function normalize(array $decodedJson): array
    {
        if (self::looksLikeQrisPaymentCallback($decodedJson)) {
            return self::fromQrisPayment($decodedJson);
        }

        return self::fromInvoice($decodedJson);
    }

    /**
     * @param  array<string, mixed>  $invoice
     * @return array<string, mixed>
     */
    public static function fromInvoice(array $invoice): array
    {
        return [
            'externalReference' => (string) ($invoice['external_id'] ?? ''),
            'status' => self::mapInvoiceStatus((string) ($invoice['status'] ?? '')),
            'eventId' => self::eventIdempotencyKey($invoice),
            'occurredAt' => self::resolveOccurredAt($invoice),
            'paymentMethod' => self::inferPaymentMethod($invoice),
            'payload' => $invoice,
        ];
    }

    /**
     * @param  array<string, mixed>  $payment
     * @return array<string, mixed>
     */
    public static function fromQrisPayment(array $payment): array
    {
        $qr = isset($payment['qr_code']) && is_array($payment['qr_code']) ? $payment['qr_code'] : [];
        $externalReference = (string) ($payment['external_id'] ?? $qr['external_id'] ?? '');
        $status = self::mapQrisPaymentStatus((string) ($payment['status'] ?? ''));
        $eventId = self::eventIdempotencyKeyFromQrisPayment($payment);

        return [
            'externalReference' => $externalReference,
            'status' => $status,
            'eventId' => $eventId,
            'occurredAt' => self::resolveOccurredAt($payment),
            'paymentMethod' => 'qris',
            'payload' => $payment,
        ];
    }

    /**
     * @param  array<string, mixed>  $payment
     */
    private static function eventIdempotencyKeyFromQrisPayment(array $payment): string
    {
        $id = (string) ($payment['id'] ?? 'unknown');
        $status = (string) ($payment['status'] ?? '');

        return 'xendit-qris-payment#'.$id.'#'.$status;
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private static function eventIdempotencyKey(array $invoice): string
    {
        $id = (string) ($invoice['id'] ?? 'unknown');
        $status = (string) ($invoice['status'] ?? '');

        return 'xendit-invoice#'.$id.'#'.$status;
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private static function resolveOccurredAt(array $invoice): string
    {
        foreach (['updated', 'updated_at', 'paid_at', 'expiry_date', 'created', 'created_at'] as $key) {
            $v = $invoice[$key] ?? null;
            if (is_string($v) && trim($v) !== '') {
                try {
                    return Carbon::parse($v)->toIso8601String();
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return now()->toIso8601String();
    }

    private static function mapInvoiceStatus(string $status): string
    {
        $u = strtoupper(trim($status));

        return match ($u) {
            'PAID', 'SETTLED' => 'paid',
            'EXPIRED' => 'expired',
            'FAILED', 'REJECTED' => 'failed',
            'CANCELLED' => 'failed',
            'PENDING', 'AWAITING_PAYMENT', 'DRAFT' => 'pending',
            default => 'pending',
        };
    }

    private static function mapQrisPaymentStatus(string $status): string
    {
        return match (strtoupper(trim($status))) {
            'COMPLETED', 'PAID', 'SETTLED' => 'paid',
            'EXPIRED' => 'expired',
            'FAILED', 'CANCELLED' => 'failed',
            default => 'pending',
        };
    }

    /**
     * @param  array<string, mixed>  $decodedJson
     */
    private static function looksLikeQrisPaymentCallback(array $decodedJson): bool
    {
        if (isset($decodedJson['qr_code']) && is_array($decodedJson['qr_code'])) {
            return true;
        }

        if (isset($decodedJson['payment_detail_source']) || isset($decodedJson['channel_code'])) {
            return true;
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $invoice
     */
    private static function inferPaymentMethod(array $invoice): ?string
    {
        $channel = $invoice['payment_channel'] ?? $invoice['payment_method'] ?? null;
        if (! is_string($channel) || trim($channel) === '') {
            return 'qris';
        }

        $c = strtolower(trim($channel));
        if (str_contains($c, 'qris')) {
            return 'qris';
        }
        if (str_contains($c, 'ewallet') || str_contains($c, 'ovo') || str_contains($c, 'dana')) {
            return 'ewallet';
        }
        if (str_contains($c, 'card') || str_contains($c, 'credit')) {
            return 'cashless';
        }
        if (str_contains($c, 'va') || str_contains($c, 'bank')) {
            return 'bank_transfer';
        }

        return 'qris';
    }
}
