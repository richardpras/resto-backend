<?php

namespace App\Models\Modules\Accounting\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journal extends Model
{
    protected $fillable = [
        'tenant_id',
        'journal_no',
        'source_type',
        'source_id',
        'journal_date',
        'status',
        'description',
        'outlet',
        'created_by',
    ];

    protected $casts = [
        'journal_date' => 'date',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(Ledger::class);
    }
}
