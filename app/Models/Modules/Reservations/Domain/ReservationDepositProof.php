<?php

namespace App\Models\Modules\Reservations\Domain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReservationDepositProof extends Model
{
    protected $fillable = [
        'reservation_id',
        'file_path',
        'original_filename',
        'uploaded_at',
        'status',
    ];

    protected $casts = [
        'uploaded_at' => 'datetime',
    ];

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }
}
