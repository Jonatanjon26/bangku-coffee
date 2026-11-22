<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\Permission;
use App\Models\Concerns\Audited;
use Database\Factories\UserFactory;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Hash;

/**
 * Karyawan. Email & kata sandi hanya untuk yang masuk panel; kasir cukup PIN (di-hash).
 * Hak akses berasal dari peran (diatur di admin) dan selalu dicek di server.
 */
// is_admin sengaja tidak fillable: hanya diisi lewat forceFill (seeder) atau kode admin.
#[Fillable(['name', 'email', 'password', 'role_id', 'pin', 'is_active'])]
#[Hidden(['password', 'remember_token', 'pin'])]
class User extends Authenticatable implements FilamentUser
{
    /** @use HasFactory<UserFactory> */
    use Audited, HasFactory, Notifiable;

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
            'pin_length' => 'integer',
            'is_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /** PIN di-hash saat diisi; panjangnya dicatat (pin_length) untuk validasi pengaturan panjang PIN. */
    protected function pin(): Attribute
    {
        return Attribute::make(set: function (?string $value): array {
            if (blank($value)) {
                return ['pin' => null, 'pin_length' => null];
            }

            return Hash::isHashed($value) ? ['pin' => $value] : ['pin' => Hash::make($value), 'pin_length' => strlen($value)];
        });
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->is_active && ($this->is_admin || $this->hasPermission(Permission::Admin));
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** Super-admin (is_admin) punya semua hak; karyawan nonaktif tidak punya hak apa pun. */
    public function hasPermission(Permission $permission): bool
    {
        if (! $this->is_active) {
            return false;
        }

        return $this->is_admin || (bool) $this->role?->hasPermission($permission);
    }

    public function checkPin(string $pin): bool
    {
        return $this->pin !== null && Hash::check($pin, $this->pin);
    }

    /**
     * PIN kasir harus unik di antara karyawan aktif. PIN di-hash, jadi dicek satu per satu
     * (jumlah karyawan sedikit) — tidak bisa memakai index unik.
     */
    public static function pinInUse(string $pin, ?int $exceptUserId = null): bool
    {
        return self::active()
            ->whereNotNull('pin')
            ->when($exceptUserId, fn (Builder $q) => $q->whereKeyNot($exceptUserId))
            ->get(['id', 'pin'])
            ->contains(fn (self $user) => $user->checkPin($pin));
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** Karyawan dengan hak tertentu lewat perannya (mis. daftar kasir di tablet, penyetuju). */
    public function scopeWithPermission(Builder $query, Permission $permission): void
    {
        $query->active()->where(fn (Builder $q) => $q
            ->where('is_admin', true)
            ->orWhereHas('role.rolePermissions', fn (Builder $r) => $r->where('permission', $permission->value)));
    }
}
