<?php

namespace App\Modules\Settings\Support;

use Illuminate\Support\Facades\File;

class TemplateSettingsPayload
{
    /** @return array<string, mixed> */
    public static function load(): array
    {
        $path = database_path('data/default_app_settings.json');
        $raw = File::get($path);

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }
}
