<?php

namespace App\Models\Modules\Accounting\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Journal extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'journal_no',
        'source_type',
        'source_id',
        'reversal_of_journal_id',
        'reversal_journal_id',
        'reversed_journal_id',
        'journal_date',
        'status',
        'posted_at',
        'reversed_at',
        'posted_by',
        'reversed_by_user_id',
        'immutable',
        'description',
        'outlet',
        'created_by',
    ];

    protected $casts = [
        'journal_date' => 'date',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
        'immutable' => 'boolean',
    ];

    public function entries(): HasMany
    {
        return $this->hasMany(JournalEntry::class);
    }

    public function linkedOutlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    public function ledgers(): HasMany
    {
        return $this->hasMany(Ledger::class);
    }
}
