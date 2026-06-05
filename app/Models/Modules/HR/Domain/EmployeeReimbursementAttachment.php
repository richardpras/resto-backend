<?php

namespace App\Models\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EmployeeReimbursementAttachment extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'reimbursement_id',
        'file_name',
        'file_path',
        'file_size',
        'mime_type',
        'created_at',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'created_at' => 'datetime',
    ];

    public function reimbursement(): BelongsTo
    {
        return $this->belongsTo(EmployeeReimbursement::class, 'reimbursement_id');
    }
}
