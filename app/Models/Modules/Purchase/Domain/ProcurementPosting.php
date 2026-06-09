<?php

namespace App\Models\Modules\Purchase\Domain;

use App\Models\Modules\Accounting\Domain\Journal;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProcurementPosting extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    public const SOURCE_GRN = 'grn';

    public const SOURCE_INVOICE = 'invoice';

    public const SOURCE_SUPPLIER_PAYMENT = 'supplier_payment';

    protected $fillable = [
        'posting_no',
        'outlet_id',
        'source_type',
        'source_id',
        'journal_entry_id',
        'amount',
        'status',
        'posted_at',
        'posted_by',
        'reversed_at',
        'reversed_by',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function journal(): BelongsTo
    {
        return $this->belongsTo(Journal::class, 'journal_entry_id');
    }

    public function postedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }

    public function reversedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by');
    }
}
