<?php

namespace App\Modules\Orders\Services;

use App\Models\Modules\Orders\Domain\PosIdempotencyKey;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PosIdempotencyService
{
    /**
     * @template T
     * @param array<string,mixed> $payload
     * @param \Closure():T $callback
     * @return T
     */
    public function run(string $scope, ?string $idempotencyKey, array $payload, \Closure $callback): mixed
    {
        if (! is_string($idempotencyKey) || trim($idempotencyKey) === '') {
            return $callback();
        }

        $normalizedKey = trim($idempotencyKey);
        $requestHash = hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');

        $existing = PosIdempotencyKey::query()
            ->where('scope', $scope)
            ->where('idempotency_key', $normalizedKey)
            ->lockForUpdate()
            ->first();
        if ($existing !== null) {
            if ((string) $existing->request_hash !== $requestHash) {
                throw ValidationException::withMessages([
                    'idempotencyKey' => ['Idempotency key already used with a different payload.'],
                ]);
            }
            throw ValidationException::withMessages([
                'idempotencyKey' => ['Duplicate request detected.'],
            ]);
        }

        $result = $callback();

        PosIdempotencyKey::query()->create([
            'scope' => $scope,
            'idempotency_key' => $normalizedKey,
            'request_hash' => $requestHash,
            'processed_at' => now(),
        ]);

        return $result;
    }
}
