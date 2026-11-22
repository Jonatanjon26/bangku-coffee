<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Device;
use App\Models\User;

/**
 * Persetujuan dengan PIN karyawan berhak "approve" (owner/kepala toko) di tablet.
 * Salah berulang mengunci persetujuan di tablet itu sementara (batas & lama dari pengaturan).
 */
class Approval
{
    public function __construct(private readonly Settings $settings) {}

    /** @throws PosLoginException */
    public function verify(Device $device, string $pin, string $purpose, array $details = []): User
    {
        $lock = new AttemptLock('pos-approve:device:'.$device->id);
        $max = (int) $this->settings->get('policy.max_pin_attempts');

        // Jatah diambil sebelum bcrypt (permintaan paralel tidak bisa melewati batas).
        if (! $lock->take($max)) {
            throw new PosLoginException(PosLoginException::LOCKED, max($lock->lockedFor(), 1));
        }

        $approver = preg_match('/^\d{'.(int) $this->settings->get('policy.pin_length').'}$/', $pin) === 1
            ? User::withPermission(Permission::Approve)->whereNotNull('pin')->get()->first(fn (User $u) => $u->checkPin($pin))
            : null;

        if (! $approver) {
            if ($seconds = $lock->failed($max, (int) $this->settings->get('policy.lock_seconds'))) {
                AuditLog::record('auth.approval_locked', null, null, ['purpose' => $purpose, 'seconds' => $seconds], device: $device);

                throw new PosLoginException(PosLoginException::LOCKED, $seconds);
            }

            throw new PosLoginException(PosLoginException::WRONG_PIN, remaining: $max - $lock->failures());
        }

        $lock->succeeded();
        AuditLog::record('approval.'.$purpose, null, null, $details, approvedBy: $approver, device: $device);

        return $approver;
    }
}
