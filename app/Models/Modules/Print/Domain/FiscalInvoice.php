<?php

namespace App\Models\Modules\Print\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FiscalInvoice extends Model
{
    protected $fillable = [
        'outlet_id',
        'fiscal_uuid',
        'invoice_number',
        'invoice_sequence_id',
        'sequence_value',
        'source_type',
        'source_id',
        'metadata',
        'issued_at',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'metadata' => 'array',
        'issued_at' => 'datetime',
        'sequence_value' => 'integer',
    ];

    /** @return BelongsTo<InvoiceSequence, FiscalInvoice> */
    public function sequence(): BelongsTo
    {
        return $this->belongsTo(InvoiceSequence::class, 'invoice_sequence_id');
    }

    /** @return HasMany<ReceiptRenderHistory, FiscalInvoice> */
    public function renderHistories(): HasMany
    {
        return $this->hasMany(ReceiptRenderHistory::class, 'fiscal_invoice_id');
    }
}
