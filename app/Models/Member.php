<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    protected $fillable = [
        'name',
        'phone',
        'email',
        'birthday',
        'notes',
        'points',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'points' => 'integer',
        ];
    }
}
