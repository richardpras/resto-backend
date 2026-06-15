<?php

namespace App\Modules\Orders\Support;

class QrOrderCodeParser
{
    public function parse(?string $input): ?string
    {
        if ($input === null) {
            return null;
        }

        $trimmed = trim($input);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('~\/qr\/order\/([^?#]+)~i', $trimmed, $matches) === 1) {
            $fromUrl = $this->normalizePublicLookupCode(urldecode($matches[1]));
            if ($fromUrl !== null) {
                return $fromUrl;
            }
        }

        $normalized = $this->normalizePublicLookupCode($trimmed);
        if ($normalized !== null) {
            return $normalized;
        }

        if (preg_match('/(QRO-?[A-Z0-9]{6,20})/i', $trimmed, $matches) === 1) {
            $code = strtoupper($matches[1]);

            return str_starts_with($code, 'QRO-') ? $code : 'QRO-'.substr($code, 3);
        }

        if (preg_match('/^QRO([A-Z0-9]{6,20})$/i', $trimmed, $matches) === 1) {
            return 'QRO-'.strtoupper($matches[1]);
        }

        return null;
    }

    public function normalizePublicLookupCode(string $orderCode): ?string
    {
        $normalized = strtoupper(trim($orderCode));
        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^QRO-[A-Z0-9]{6,16}$/', $normalized) === 1) {
            return $normalized;
        }

        if (preg_match('/^DEMO-[A-Z0-9]+-QRO-[A-Z0-9-]+$/', $normalized) === 1) {
            return $normalized;
        }

        return null;
    }
}
