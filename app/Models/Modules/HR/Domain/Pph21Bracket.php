<?php

namespace App\Models\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pph21Bracket extends Model
{
    protected $fillable = [
        'pph21_config_id',
        'income_from',
        'income_to',
        'tax_rate',
    ];

    protected $casts = [
        'income_from' => 'float',
        'income_to' => 'float',
        'tax_rate' => 'float',
    ];

    public function config(): BelongsTo
    {
        return $this->belongsTo(Pph21Config::class, 'pph21_config_id');
    }
}
