<?php

namespace App\Modules\System\Services;

final class BugReportDiagnosticsSanitizer
{
    /** @var list<string> */
    private const SENSITIVE_KEYS = [
        'authorization',
        'cookie',
        'cookies',
        'password',
        'passwd',
        'secret',
        'token',
        'bearer',
        'api_key',
        'apikey',
        'card',
        'cvv',
        'cvc',
        'pin',
        'x-api-key',
    ];

    /**
     * @param  array<string, mixed>|null  $diagnostics
     * @return array<string, mixed>|null
     */
    public function sanitize(?array $diagnostics): ?array
    {
        if ($diagnostics === null) {
            return null;
        }

        return $this->sanitizeValue($diagnostics);
    }

    /**
     * @return array<string, mixed>|list<mixed>|string|int|float|bool|null
     */
    private function sanitizeValue(mixed $value): mixed
    {
        if (is_array($value)) {
            $sanitized = [];
            foreach ($value as $key => $item) {
                if (is_string($key) && $this->isSensitiveKey($key)) {
                    $sanitized[$key] = '[REDACTED]';

                    continue;
                }
                $sanitized[$key] = $this->sanitizeValue($item);
            }

            return $sanitized;
        }

        if (is_string($value)) {
            return $this->sanitizeString($value);
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $normalized = strtolower(str_replace(['-', '_'], '', $key));
        foreach (self::SENSITIVE_KEYS as $sensitive) {
            $needle = str_replace(['-', '_'], '', $sensitive);
            if (str_contains($normalized, $needle)) {
                return true;
            }
        }

        return false;
    }

    private function sanitizeString(string $value): string
    {
        $redacted = preg_replace('/Bearer\s+[A-Za-z0-9\-._~+\/]+=*/i', 'Bearer [REDACTED]', $value) ?? $value;
        $redacted = preg_replace('/eyJ[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]+\.[A-Za-z0-9\-_]*/', '[JWT_REDACTED]', $redacted) ?? $redacted;

        return $redacted;
    }
}
