<?php

namespace App\Models\Modules\UserManagement\Domain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Role extends Model
{
    protected $fillable = [
        'name',
        'description',
        'staff_assignable',
        'hierarchy_rank',
    ];

    protected function casts(): array
    {
        return [
            'staff_assignable' => 'boolean',
            'hierarchy_rank' => 'integer',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'role_user');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'permission_role');
    }
}
