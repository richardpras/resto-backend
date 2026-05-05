<?php

namespace Database\Seeders\Concerns;

use Illuminate\Support\Facades\File;

trait LoadsTemplatePayrollData
{
    /**
     * @return array<string, mixed>
     */
    protected function templatePayrollData(): array
    {
        $path = database_path('data/template_payroll.json');

        return json_decode(File::get($path), true, 512, JSON_THROW_ON_ERROR);
    }
}
