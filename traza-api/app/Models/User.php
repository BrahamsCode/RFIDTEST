<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\RoleCode;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Adaptado al esquema de TRAZA: todo usuario pertenece a una organización y
 * tiene una ubicación por defecto. No hay `email_verified_at`: las altas las
 * hace un administrador, no un registro público.
 */
class User extends Authenticatable
{
    use HasApiTokens, Notifiable;

    protected $fillable = [
        'organization_id',
        'name',
        'email',
        'password',
        'default_location_id',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function defaultLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'default_location_id');
    }

    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class);
    }

    /** @return list<RoleCode> */
    public function roleCodes(): array
    {
        return $this->relationLoaded('roles')
            ? $this->roles->map(fn (Role $r) => RoleCode::from($r->code))->all()
            : $this->roles()->pluck('code')->map(RoleCode::from(...))->all();
    }

    public function hasRole(RoleCode|string $code): bool
    {
        $value = $code instanceof RoleCode ? $code->value : $code;

        return in_array($value, array_map(fn (RoleCode $r) => $r->value, $this->roleCodes()), strict: true);
    }

    /** @param list<RoleCode|string> $codes */
    public function hasAnyRole(array $codes): bool
    {
        foreach ($codes as $code) {
            if ($this->hasRole($code)) {
                return true;
            }
        }

        return false;
    }

    /** ¿Alguno de sus roles concede este permiso? */
    public function hasPermission(string $permission): bool
    {
        foreach ($this->roleCodes() as $role) {
            if ($role->can($permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Un usuario de tienda solo opera en la suya. Los roles multi-tienda
     * (`supervisor_regional`, `gerencia`, `tecnico`, `admin`) ven todas las
     * de su organización, pero nunca las de otra.
     */
    public function canAccessLocation(?int $locationId): bool
    {
        if ($locationId === null) {
            return false;
        }

        foreach ($this->roleCodes() as $role) {
            if ($role->isMultiLocation()) {
                return Location::query()
                    ->whereKey($locationId)
                    ->where('organization_id', $this->organization_id)
                    ->exists();
            }
        }

        return $this->default_location_id === $locationId;
    }
}
