<?php

namespace App\Models\Modules\Print\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReceiptTemplate extends Model
{
    protected $fillable = [
        'outlet_id',
        'kind',
        'code',
        'version',
        'name',
        'thermal_width_chars',
        'printer_profile_id',
        'sections',
        'defaults',
        'is_active',
        'is_default_fallback',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'sections' => 'array',
        'defaults' => 'array',
        'is_active' => 'boolean',
        'is_default_fallback' => 'boolean',
        'thermal_width_chars' => 'integer',
        'version' => 'integer',
    ];

    /** @return BelongsTo<PrinterProfile, ReceiptTemplate> */
    public function printerProfile(): BelongsTo
    {
        return $this->belongsTo(PrinterProfile::class, 'printer_profile_id');
    }

    /** @return HasMany<ReceiptRenderHistory, ReceiptTemplate> */
    public function renders(): HasMany
    {
        return $this->hasMany(ReceiptRenderHistory::class, 'receipt_template_id');
    }
}
