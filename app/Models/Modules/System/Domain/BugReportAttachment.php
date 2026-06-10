<?php

namespace App\Models\Modules\System\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BugReportAttachment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'bug_report_id',
        'file_path',
        'file_type',
        'file_size',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<BugReport, $this> */
    public function bugReport(): BelongsTo
    {
        return $this->belongsTo(BugReport::class);
    }
}
