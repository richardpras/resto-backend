<?php

namespace App\Modules\Orders\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

class OptimisticConcurrencyService
{
    public function assertNotStale(Model $model, ?string $expectedUpdatedAt): void
    {
        if (! is_string($expectedUpdatedAt) || trim($expectedUpdatedAt) === '') {
            return;
        }

        $expected = CarbonImmutable::parse($expectedUpdatedAt)->utc();
        $actual = $model->updated_at?->copy()?->utc();
        if ($actual === null || ! $actual->equalTo($expected)) {
            throw ValidationException::withMessages([
                'expectedUpdatedAt' => ['Resource was modified by another request. Refresh and retry.'],
            ]);
        }
    }
}
