<?php

namespace App\Models\Modules\Orders\Domain;

use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosSessionCashMovement extends Model
{
    public const DIRECTION_IN = 'in';

    public const DIRECTION_OUT = 'out';

    /** @var list<string> */
    public const OUT_CATEGORIES = ['iuran', 'operasional', 'beli_bahan_darurat', 'lainnya'];

    /** @var list<string> */
    public const IN_CATEGORIES = ['setor_modal', 'dari_brankas', 'lainnya'];

    protected $fillable = [
        'outlet_id',
        'pos_session_id',
        'direction',
        'amount',
        'category',
        'notes',
        'created_by_user_id',
        'occurred_at',
        'client_local_ref',
        'journal_id',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'occurred_at' => 'datetime',
    ];

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(Outlet::class);
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(PosSession::class, 'pos_session_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return list<string> */
    public static function categoriesForDirection(string $direction): array
    {
        return $direction === self::DIRECTION_IN ? self::IN_CATEGORIES : self::OUT_CATEGORIES;
    }
}
