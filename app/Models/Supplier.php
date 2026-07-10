<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Supplier extends Model
{
    protected $fillable = [
        'import_code',
        'name',
        'contact',
        'email',
        'address',
        'notes',
        'status',
        'payment_term_days',
        'lead_time_days',
        'tax_number',
        'tax_name',
        'tax_address',
        'contact_person',
        'contact_phone',
        'contact_email',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'payment_term_days' => 'integer',
        'lead_time_days' => 'integer',
    ];
}
