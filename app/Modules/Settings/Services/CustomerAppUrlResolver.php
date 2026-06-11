<?php

namespace App\Modules\Settings\Services;

use App\Models\Modules\Settings\Domain\SystemSetting;

class CustomerAppUrlResolver
{
    public function resolve(): ?string
    {
        $fromSettings = trim((string) (SystemSetting::query()->value('customer_app_url') ?? ''));
        if ($fromSettings !== '') {
            return $this->normalizeBaseUrl($fromSettings);
        }

        $frontend = trim((string) config('app.frontend_url', ''));
        if ($frontend !== '') {
            return $this->normalizeBaseUrl($frontend);
        }

        $appUrl = trim((string) config('app.url', ''));
        if ($appUrl !== '') {
            return $this->normalizeBaseUrl($appUrl);
        }

        return null;
    }

    public function isValidConfiguredUrl(?string $url = null): bool
    {
        $candidate = $url ?? $this->resolve();
        if ($candidate === null || $candidate === '') {
            return false;
        }

        return filter_var($candidate, FILTER_VALIDATE_URL) !== false;
    }

    private function normalizeBaseUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }
}
