<?php

namespace App\Models\Modules\Print\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InvoiceSequence extends Model
{
    protected $fillable = [
        'outlet_id',
        'series_key',
        'prefix',
        'pad_length',
        'next_value',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'pad_length' => 'integer',
        'next_value' => 'integer',
    ];

    /** @return BelongsTo<Outlet, InvoiceSequence> */
    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }

    /** @return HasMany<FiscalInvoice, InvoiceSequence> */
    public function invoices(): HasMany
    {
        return $this->hasMany(FiscalInvoice::class, 'invoice_sequence_id');
    }
}
