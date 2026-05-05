<?php

namespace App\Models\Modules\Settings\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettingPrinter extends Model
{
    protected $table = 'setting_printers';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id',
        'name',
        'printer_type',
        'connection',
        'ip',
        'bluetooth_device',
        'outlet_id',
        'assigned_categories',
    ];

    protected $casts = [
        'assigned_categories' => 'array',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id', 'id');
    }
}
