<?php

namespace App\Modules\HR\Services;

use App\Models\Modules\Settings\Domain\SystemSetting;
use Illuminate\Validation\ValidationException;

class EssFeatureService
{
    public function isEnabled(): bool
    {
        $row = SystemSetting::query()->first();

        return (bool) ($row?->employee_self_service_enabled ?? false);
    }

    public function assertEnabled(): void
    {
        if (! $this->isEnabled()) {
            throw ValidationException::withMessages([
                'employeeSelfService' => ['Employee self service is disabled.'],
            ]);
        }
    }
}
