<?php

namespace App\Modules\Settings\Services;

use App\Models\Modules\Settings\Domain\AppSetting;
use Illuminate\Support\Facades\File;

class SettingsService
{
    /** @return array<string, mixed> */
    public function defaultPayload(): array
    {
        $path = database_path('data/default_app_settings.json');
        $raw = File::get($path);

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    public function get(): array
    {
        $row = AppSetting::query()->first();

        return $row?->payload ?? $this->defaultPayload();
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function put(array $payload): array
    {
        $model = AppSetting::query()->first() ?? new AppSetting;
        $model->payload = $payload;
        $model->save();

        return $payload;
    }
}
