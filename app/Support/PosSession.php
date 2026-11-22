<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Sesi kasir di tablet (guard "pos", terpisah dari login panel admin). Sesi terikat ke tablet,
 * berakhir bila karyawan nonaktif/kehilangan hak kasir, dan terkunci otomatis setelah tidak dipakai
 * (policy.auto_lock_minutes) — dicek di server, bukan hanya di browser.
 */
class PosSession
{
    private const DEVICE = 'pos.device_id';

    private const LAST_ACTIVITY = 'pos.last_activity';

    public function __construct(private readonly Settings $settings) {}

    public function start(User $user, Device $device): void
    {
        // Sesi panel admin di browser tablet ikut diakhiri: kasir berikutnya tidak mewarisi akses owner.
        Auth::guard('web')->logout();
        Auth::guard('pos')->login($user);
        session()->regenerate();
        session([self::DEVICE => $device->id, self::LAST_ACTIVITY => now()->getTimestamp()]);
    }

    /** Kunci kasir: hanya sesi kasir yang diakhiri; shift tetap berjalan. */
    public function end(): void
    {
        Auth::guard('pos')->logout();
        Auth::guard('web')->logout();
        session()->forget([self::DEVICE, self::LAST_ACTIVITY]);
        session()->regenerateToken();
    }

    /** Kasir yang masih sah di tablet ini, atau null (sesi diakhiri bila tidak sah). */
    public function user(Device $device): ?User
    {
        $user = Auth::guard('pos')->user();

        if (! $user instanceof User || ! $user->hasPermission(Permission::Pos) || session(self::DEVICE) !== $device->id) {
            if ($user) {
                $this->end();
            }

            return null;
        }

        return $user;
    }

    public function idleExpired(): bool
    {
        $minutes = (int) $this->settings->get('policy.auto_lock_minutes');

        return $minutes > 0 && now()->getTimestamp() - (int) session(self::LAST_ACTIVITY, 0) > $minutes * 60;
    }

    public function touch(): void
    {
        session([self::LAST_ACTIVITY => now()->getTimestamp()]);
    }
}
