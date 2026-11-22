<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\DevicePairingCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * Masuk kasir di tablet: daftar tablet (kode sekali pakai) → pilih kasir → PIN.
 * Semua aturan di server: kode & PIN dibandingkan dengan hash, salah berulang dikunci sementara
 * per alamat IP (kode), per karyawan, dan per tablet (PIN); batas & lama kunci dari pengaturan.
 */
class PosLogin
{
    public function __construct(private readonly Settings $settings) {}

    /* ---------------------------------------------------------------- tablet */

    public function currentDevice(Request $request): ?Device
    {
        $token = $request->cookie(config('pos.device_cookie.name'));

        return is_string($token) ? Device::findByToken($token) : null;
    }

    public function deviceCookie(string $token): Cookie
    {
        return cookie(config('pos.device_cookie.name'), $token, config('pos.device_cookie.days') * 24 * 60, httpOnly: true, sameSite: 'lax');
    }

    public function forgetDeviceCookie(): Cookie
    {
        return cookie()->forget(config('pos.device_cookie.name'));
    }

    /**
     * Daftarkan tablet dengan kode dari admin. Mengembalikan [perangkat, token asli untuk cookie].
     *
     * @return array{0: Device, 1: string}
     *
     * @throws PosLoginException
     */
    public function pair(string $code, string $ip): array
    {
        // Per IP dan global (kode 6 digit; rotasi IP tidak menambah jumlah tebakan).
        $lock = new AttemptLock('pos-pair:'.$ip);
        $global = new AttemptLock('pos-pair:global');
        $globalMax = (int) config('pos.pairing_global_max_attempts');

        if (! $lock->take($this->maxAttempts())) {
            throw new PosLoginException(PosLoginException::LOCKED, max($lock->lockedFor(), 1));
        }

        if (! $global->take($globalMax)) {
            $lock->release();

            throw new PosLoginException(PosLoginException::LOCKED, max($global->lockedFor(), 1));
        }

        $result = DB::transaction(function () use ($code) {
            $row = DevicePairingCode::where('code_hash', DevicePairingCode::hashCode($code))
                ->whereNull('used_at')
                ->where('expires_at', '>', now())
                ->lockForUpdate()
                ->first();

            if (! $row) {
                return null;
            }

            [$device, $token] = Device::register($row->device_name, $row->created_by ? User::find($row->created_by) : null);
            $row->forceFill(['used_at' => now(), 'device_id' => $device->id])->save();

            return [$device, $token];
        });

        if ($result === null) {
            $seconds = max($lock->failed($this->maxAttempts(), $this->lockSeconds()), $global->failed($globalMax, $this->lockSeconds()));

            if ($seconds) {
                AuditLog::record('auth.pairing_locked', null, null, ['scope' => $global->lockedFor() ? 'global' : 'ip', 'seconds' => $seconds]);

                throw new PosLoginException(PosLoginException::LOCKED, $seconds);
            }

            throw new PosLoginException(PosLoginException::INVALID_CODE);
        }

        $lock->succeeded();
        $global->release();

        return $result;
    }

    /* ---------------------------------------------------------------- kasir */

    /** Karyawan aktif yang punya hak "pos" (ditampilkan di tablet terdaftar). */
    public function cashiers(): Collection
    {
        return User::withPermission(Permission::Pos)->with('role')->orderBy('name')->get();
    }

    public function pinLength(): int
    {
        return (int) $this->settings->get('policy.pin_length');
    }

    /**
     * Panjang PIN karyawan ini: PIN lama tetap berlaku sampai diganti setelah panjang PIN di pengaturan
     * diubah (masa peralihan); PIN baru selalu sepanjang pengaturan.
     */
    public function pinLengthFor(?User $user): int
    {
        return (int) ($user?->pin_length ?: $this->pinLength());
    }

    /** Sisa detik kunci untuk karyawan di tablet ini (0 = tidak terkunci). */
    public function lockedFor(Device $device, int $userId): int
    {
        return max($this->userLock($userId)->lockedFor(), $this->deviceLock($device)->lockedFor());
    }

    /**
     * Cek PIN. Berhasil → karyawan (belum dimasukkan ke sesi; lihat Livewire Login).
     *
     * @throws PosLoginException
     */
    public function attempt(Device $device, int $userId, string $pin): User
    {
        $user = $this->cashiers()->firstWhere('id', $userId);

        if (! $user) {
            throw new PosLoginException(PosLoginException::NOT_ALLOWED);
        }

        $userLock = $this->userLock($user->id);
        $deviceLock = $this->deviceLock($device);
        $deviceMax = (int) $this->settings->get('policy.max_pin_attempts_device');

        // Jatah diambil sebelum bcrypt: permintaan paralel tidak bisa melewati batas.
        if (! $userLock->take($this->maxAttempts())) {
            throw new PosLoginException(PosLoginException::LOCKED, max($this->lockedFor($device, $user->id), 1));
        }

        if (! $deviceLock->take($deviceMax)) {
            $userLock->release();

            throw new PosLoginException(PosLoginException::LOCKED, max($this->lockedFor($device, $user->id), 1));
        }

        $valid = preg_match('/^\d{'.$this->pinLengthFor($user).'}$/', $pin) === 1 && $user->checkPin($pin);

        if (! $valid) {
            $userSeconds = $userLock->failed($this->maxAttempts(), $this->lockSeconds());
            $deviceSeconds = $deviceLock->failed($deviceMax, $this->lockSeconds());

            if ($seconds = max($userSeconds, $deviceSeconds)) {
                AuditLog::record('auth.pin_locked', $user, null, ['scope' => $deviceSeconds ? 'device' : 'user', 'seconds' => $seconds], user: $user, device: $device);

                throw new PosLoginException(PosLoginException::LOCKED, $seconds);
            }

            throw new PosLoginException(PosLoginException::WRONG_PIN, remaining: $this->maxAttempts() - $userLock->failures());
        }

        // Hitungan per tablet tidak direset saat berhasil: kasir yang tahu PIN sendiri tidak bisa
        // menghapus jejak tebakan PIN karyawan lain.
        $userLock->succeeded();
        $deviceLock->release();

        return $user;
    }

    /**
     * Karyawan mengganti PIN sendiri di tablet: PIN lama wajib benar, PIN baru sepanjang pengaturan & unik.
     * Setiap percobaan (PIN lama salah maupun "PIN tidak bisa dipakai") memakai jatah yang sama dan dikunci
     * bertingkat — pesan PIN terpakai tidak bisa dipakai menebak PIN orang lain.
     *
     * @throws PosLoginException PIN lama salah / terkunci
     * @throws ValidationException PIN baru tidak valid
     */
    public function changePin(User $user, Device $device, string $current, string $new, string $confirm): void
    {
        $lock = new AttemptLock('pos-pin-change:user:'.$user->id);
        $max = $this->maxAttempts();

        if (! $lock->take($max)) {
            throw new PosLoginException(PosLoginException::LOCKED, max($lock->lockedFor(), 1));
        }

        $fail = function (?string $error) use ($lock, $max, $user, $device) {
            if ($seconds = $lock->failed($max, $this->lockSeconds())) {
                AuditLog::record('auth.pin_change_locked', $user, null, ['seconds' => $seconds], user: $user, device: $device);

                throw new PosLoginException(PosLoginException::LOCKED, $seconds);
            }

            if ($error === null) {
                throw new PosLoginException(PosLoginException::WRONG_PIN, remaining: $max - $lock->failures());
            }

            throw ValidationException::withMessages(['pin' => $error]);
        };

        if (preg_match('/^\d{'.$this->pinLengthFor($user).'}$/', $current) !== 1 || ! $user->checkPin($current)) {
            $fail(null);
        }

        $length = $this->pinLength();

        if (preg_match('/^\d{'.$length.'}$/', $new) !== 1) {
            $lock->release();

            throw ValidationException::withMessages(['pin' => __('pos.pin_change.errors.length', ['length' => $length])]);
        }

        if ($new !== $confirm) {
            $lock->release();

            throw ValidationException::withMessages(['pin' => __('pos.pin_change.errors.confirm')]);
        }

        if ($new === $current) {
            $lock->release();

            throw ValidationException::withMessages(['pin' => __('pos.pin_change.errors.same')]);
        }

        // "PIN tidak bisa dipakai" membocorkan PIN orang lain: dihitung per hari per karyawan & per tablet dengan
        // penghitung sendiri yang TIDAK direset oleh ganti PIN yang berhasil (kalau direset, kasir bisa menebak
        // beberapa PIN, ganti ke PIN bebas, lalu menebak lagi tanpa pernah terkunci). Jumlah ganti PIN per hari juga dibatasi.
        $day = (int) config('pos.pin_change_window_seconds');
        $takenUser = new AttemptLock('pos-pin-taken:user:'.$user->id);
        $takenDevice = new AttemptLock('pos-pin-taken:device:'.$device->id);
        $changes = new AttemptLock('pos-pin-changes:user:'.$user->id);
        $takenMax = (int) config('pos.pin_taken_max_per_day');

        if (! $takenUser->take($takenMax, $day) || ! $takenDevice->take($takenMax * 2, $day) || ! $changes->take((int) config('pos.pin_changes_max_per_day'), $day)) {
            $lock->release();
            AuditLog::record('auth.pin_change_limit', $user, null, [], user: $user, device: $device);

            throw ValidationException::withMessages(['pin' => __('pos.pin_change.errors.daily_limit')]);
        }

        if (User::pinInUse($new, $user->id)) {
            $changes->release();
            $takenUser->failed($takenMax, $day);
            $takenDevice->failed($takenMax * 2, $day);
            $fail(__('pos.pin_change.errors.unavailable'));
        }

        // Jawaban "terpakai" tidak dipakai: jatahnya dikembalikan (hanya jawaban terpakai yang dihitung).
        $takenUser->release();
        $takenDevice->release();
        $user->forceFill(['pin' => $new])->save();
        $lock->succeeded();
        AuditLog::record('user.pin_changed_self', $user, null, ['pin_length' => $length], user: $user, device: $device);
    }

    private function userLock(int $userId): AttemptLock
    {
        return new AttemptLock('pos-pin:user:'.$userId);
    }

    private function deviceLock(Device $device): AttemptLock
    {
        return new AttemptLock('pos-pin:device:'.$device->id);
    }

    private function maxAttempts(): int
    {
        return (int) $this->settings->get('policy.max_pin_attempts');
    }

    private function lockSeconds(): int
    {
        return (int) $this->settings->get('policy.lock_seconds');
    }
}
