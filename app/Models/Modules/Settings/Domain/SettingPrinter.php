<?php

namespace App\Models\Modules\Settings\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SettingPrinter extends Model
{
    protected $table = 'setting_printers';

    public $incrementing = false;

    protected $keyType = 'string';

    public const PAPER_WIDTH_58MM = '58mm';

    public const PAPER_WIDTH_80MM = '80mm';

    protected $fillable = [
        'id',
        'name',
        'printer_type',
        'connection',
        'thermal_paper_width',
        'ip',
        'bluetooth_device',
        'outlet_id',
        'assigned_categories',
        'printer_profile_id',
    ];

    protected $casts = [
        'assigned_categories' => 'array',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id', 'id');
    }
}
