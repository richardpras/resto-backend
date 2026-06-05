<?php

namespace App\Models\Modules\HR\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Pph21Config extends Model
{
    public const PTKP_STATUSES = ['TK0', 'TK1', 'TK2', 'TK3', 'K0', 'K1', 'K2', 'K3'];

    protected $fillable = [
        'effective_date',
        'ptkp_tk0',
        'ptkp_tk1',
        'ptkp_tk2',
        'ptkp_tk3',
        'ptkp_k0',
        'ptkp_k1',
        'ptkp_k2',
        'ptkp_k3',
        'is_active',
    ];

    protected $casts = [
        'effective_date' => 'date',
        'ptkp_tk0' => 'float',
        'ptkp_tk1' => 'float',
        'ptkp_tk2' => 'float',
        'ptkp_tk3' => 'float',
        'ptkp_k0' => 'float',
        'ptkp_k1' => 'float',
        'ptkp_k2' => 'float',
        'ptkp_k3' => 'float',
        'is_active' => 'boolean',
    ];

    public function brackets(): HasMany
    {
        return $this->hasMany(Pph21Bracket::class)->orderBy('income_from');
    }

    public function ptkpForStatus(string $status): float
    {
        $column = 'ptkp_'.strtolower($status);

        return (float) ($this->{$column} ?? 0);
    }
}
