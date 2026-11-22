<?php

namespace App\Support;

use RuntimeException;

/** Gagal masuk kasir / daftar tablet. Pesan sudah diterjemahkan (lang/{locale}/pos.php → login). */
class PosLoginException extends RuntimeException
{
    public const INVALID_CODE = 'invalid_code';

    public const LOCKED = 'locked';

    public const WRONG_PIN = 'wrong_pin';

    public const NOT_ALLOWED = 'not_allowed';

    public function __construct(public readonly string $reason, public readonly int $seconds = 0, public readonly int $remaining = 0)
    {
        parent::__construct(__('pos.login.errors.'.$reason, ['seconds' => $seconds, 'remaining' => $remaining]));
    }
}
