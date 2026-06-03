<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Member extends Model
{
    protected $fillable = [
        'outlet_id',
        'member_no',
        'full_name',
        'name',
        'phone',
        'email',
        'birth_date',
        'birthday',
        'gender',
        'notes',
        'is_active',
        'status',
        'points',
    ];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'birthday' => 'date',
            'is_active' => 'boolean',
            'points' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Member $member): void {
            if ($member->full_name !== null && $member->full_name !== '') {
                $member->name = $member->full_name;
            } elseif ($member->name !== null && $member->name !== '') {
                $member->full_name = $member->name;
            }

            if ($member->birth_date !== null) {
                $member->birthday = $member->birth_date;
            } elseif ($member->birthday !== null) {
                $member->birth_date = $member->birthday;
            }

            if ($member->is_active !== null) {
                $member->status = $member->is_active ? 'active' : 'inactive';
            } elseif ($member->status !== null) {
                $member->is_active = $member->status === 'active';
            }
        });
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(MemberTransaction::class);
    }

    public function displayName(): string
    {
        return (string) ($this->full_name ?: $this->name);
    }
}
