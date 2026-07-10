<?php

namespace App\Models\Modules\Imports\Domain;

use Illuminate\Database\Eloquent\Model;

class MasterImportBatch extends Model
{
    protected $fillable = [
        'outlet_id',
        'tenant_id',
        'import_type',
        'filename',
        'created_count',
        'updated_count',
        'skipped_count',
        'error_count',
        'summary_json',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'summary_json' => 'array',
        ];
    }
}
