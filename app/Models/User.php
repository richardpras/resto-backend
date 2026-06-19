<?php

namespace App\Models;

use App\Models\Modules\HR\Domain\Employee;
use App\Models\Modules\Settings\Domain\Outlet;
use App\Models\Modules\UserManagement\Domain\Permission;
use App\Models\Modules\UserManagement\Domain\Role;
// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Passport\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'pin_hash',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'pin_hash',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'pin_hash' => 'hashed',
        ];
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function permissions()
    {
        return Permission::query()
            ->whereIn('id', function ($query) {
                $query->select('permission_id')
                    ->from('permission_role')
                    ->whereIn('role_id', $this->roles()->pluck('roles.id'));
            });
    }

    /**
     * Permission codes that satisfy a gate when the user holds any of the listed codes.
     * Example: floor managers often have {@code tables.manage} without a separate {@code tables.view} row.
     *
     * @var array<string, list<string>>
     */
    private const PERMISSION_SATISFIED_BY_ANY_OF = [
        'tables.view' => ['tables.view', 'tables.manage'],
        'purchase.approve' => ['purchase.approve', 'purchase.manage'],
    ];

    public function hasPermission(string $permissionCode): bool
    {
        $candidates = self::PERMISSION_SATISFIED_BY_ANY_OF[$permissionCode] ?? [$permissionCode];

        return $this->permissions()->whereIn('code', $candidates)->exists();
    }

    public function isSuperAdmin(): bool
    {
        return $this->roles()
            ->whereRaw('LOWER(name) = ?', ['super_admin'])
            ->exists();
    }

    public function employee()
    {
        return $this->hasOne(Employee::class);
    }

    public function outlets(): BelongsToMany
    {
        return $this->belongsToMany(Outlet::class, 'user_outlets');
    }
}
