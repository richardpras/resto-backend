<?php

namespace App\Models\Modules\Menu\Domain;

use App\Models\Modules\Print\Domain\PrinterProfile;
use App\Models\Modules\Settings\Domain\Outlet;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuCategoryPrinterMapping extends Model
{
    protected $fillable = [
        'tenant_id',
        'outlet_id',
        'menu_category_id',
        'printer_profile_id',
        'priority',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'priority' => 'integer',
        'is_active' => 'boolean',
        'meta' => 'array',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    public function printerProfile(): BelongsTo
    {
        return $this->belongsTo(PrinterProfile::class, 'printer_profile_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class, 'outlet_id');
    }
}
