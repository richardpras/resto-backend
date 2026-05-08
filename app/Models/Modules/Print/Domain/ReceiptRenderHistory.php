<?php

namespace App\Models\Modules\Print\Domain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReceiptRenderHistory extends Model
{
    protected $fillable = [
        'outlet_id',
        'receipt_template_id',
        'kind',
        'render_fingerprint',
        'source_type',
        'source_id',
        'order_split_id',
        'context_snapshot',
        'thermal_text',
        'html_snapshot',
        'pdf_storage_path',
        'fiscal_invoice_id',
        'issued_by_user_id',
        'reprint_count',
        'deferred_replay_pending',
        'recovery_meta',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'context_snapshot' => 'array',
        'recovery_meta' => 'array',
        'reprint_count' => 'integer',
        'deferred_replay_pending' => 'boolean',
    ];

    /** @return BelongsTo<ReceiptTemplate, ReceiptRenderHistory> */
    public function template(): BelongsTo
    {
        return $this->belongsTo(ReceiptTemplate::class, 'receipt_template_id');
    }

    /** @return BelongsTo<FiscalInvoice, ReceiptRenderHistory> */
    public function fiscalInvoice(): BelongsTo
    {
        return $this->belongsTo(FiscalInvoice::class, 'fiscal_invoice_id');
    }

    /** @return BelongsTo<User, ReceiptRenderHistory> */
    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }
}
