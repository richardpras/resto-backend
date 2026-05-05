<?php

namespace Database\Seeders;

use App\Models\Modules\Settings\Domain\AppSetting;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

/**
 * Stores only offline demo/catalog payload (templateOfflineSeed) in app_settings.payload.
 * Merchant, outlets, taxes, etc. are seeded via {@see SettingsDomainFromTemplateSeeder}.
 */
class AppSettingsFromTemplateSeeder extends Seeder
{
    public function run(): void
    {
        if (AppSetting::query()->exists()) {
            return;
        }

        $path = database_path('data/default_app_settings.json');
        $payload = json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
        $seed = $payload['templateOfflineSeed'] ?? [];

        AppSetting::query()->create([
            'payload' => ['templateOfflineSeed' => $seed],
        ]);
    }
}
