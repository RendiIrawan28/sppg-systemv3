<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens;
    use HasFactory;
    use HasRoles;
    use Notifiable;

    protected $fillable = [
        'employee_number',
        'name',
        'email',
        'phone',
        'password',
        'is_active',
        'is_super_admin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'is_super_admin' => 'boolean',
        ];
    }

    public function divisions(): BelongsToMany
    {
        return $this->belongsToMany(Division::class)
            ->withPivot([
                'sppg_unit_id',
                'position',
                'is_primary',
                'is_active',
            ])
            ->withTimestamps();
    }

    public function attendanceSessions(): HasMany
    {
        return $this->hasMany(AttendanceSession::class);
    }

    public function divisionNamesForUnit(
        int $unitId
    ): string {
        return $this->divisions()
            ->wherePivot('sppg_unit_id', $unitId)
            ->wherePivot('is_active', true)
            ->orderBy('sort_order')
            ->pluck('name')
            ->implode(', ');
    }
}
