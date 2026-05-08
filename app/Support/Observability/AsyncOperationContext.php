<?php

namespace App\Support\Observability;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AsyncOperationContext
{
    /** @var array<string,mixed> */
    private static array $runtimeContext = [];

    /**
     * @param  array<string,mixed>  $overrides
     * @return array<string,mixed>
     */
    public static function capture(array $overrides = []): array
    {
        $base = self::sanitize(self::$runtimeContext);

        $context = array_merge($base, self::sanitize($overrides));
        $context['correlation_id'] = self::stringOrFallback($context['correlation_id'] ?? null, (string) Str::uuid());
        $context['trace_id'] = self::stringOrFallback($context['trace_id'] ?? null, (string) Str::uuid());

        return $context;
    }

    /**
     * @param  array<string,mixed>  $context
     */
    public static function apply(array $context): void
    {
        self::$runtimeContext = self::capture($context);
        Log::withContext(self::$runtimeContext);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    public static function withQueueMetadata(array $context, array $queueMetadata = []): array
    {
        return self::capture(array_merge(
            self::sanitize($context),
            self::sanitize($queueMetadata)
        ));
    }

    /** @return array<string,mixed> */
    public static function current(): array
    {
        return self::capture();
    }

    private static function stringOrFallback(mixed $value, string $fallback): string
    {
        if (! is_string($value) || trim($value) === '') {
            return $fallback;
        }

        return trim($value);
    }

    /**
     * @param  array<string,mixed>  $context
     * @return array<string,mixed>
     */
    private static function sanitize(array $context): array
    {
        return array_filter(
            $context,
            static fn (mixed $value): bool => $value !== null && $value !== ''
        );
    }
}
