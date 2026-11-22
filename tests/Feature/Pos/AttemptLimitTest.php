<?php

namespace Tests\Feature\Pos;

use App\Support\Approval;
use App\Support\AttemptLock;
use App\Support\PosLogin;
use App\Support\PosLoginException;

/**
 * Batas salah PIN/kode tidak bisa ditembus dengan permintaan paralel (jatah diambil atomik sebelum
 * verifikasi) dan lama kunci berlipat bila terkunci berulang.
 */
class AttemptLimitTest extends PosTestCase
{
    public function test_jatah_diambil_sebelum_verifikasi_sehingga_paralel_tidak_lolos(): void
    {
        $lock = new AttemptLock('uji');

        // Lima percobaan "sedang berjalan" (belum selesai bcrypt) menghabiskan jatah; yang keenam ditolak.
        foreach (range(1, 5) as $i) {
            $this->assertTrue($lock->take(5), "percobaan {$i}");
        }
        $this->assertFalse($lock->take(5));

        $this->assertSame(60, $lock->failed(5, 60));
        $this->assertFalse($lock->take(5));
    }

    public function test_lama_kunci_berlipat_dan_dibatasi(): void
    {
        config(['pos.lock_max_seconds' => 300]);
        $lock = new AttemptLock('uji-eskalasi');
        $locks = [];

        foreach (range(1, 5) as $round) {
            foreach (range(1, 3) as $i) {
                $lock->take(3);
            }
            $locks[] = $lock->failed(3, 60);
            $this->travel($locks[array_key_last($locks)] + 1)->seconds();
        }

        $this->assertSame([60, 120, 240, 300, 300], $locks);
    }

    public function test_login_berhasil_berulang_tidak_mengunci_tablet(): void
    {
        [$device] = $this->tablet();
        $rani = $this->user('Rani');
        $login = app(PosLogin::class);

        foreach (range(1, 30) as $i) {
            $this->assertSame($rani->id, $login->attempt($device, $rani->id, '1111')->id);
        }

        $this->assertSame(0, $login->lockedFor($device, $rani->id));
    }

    public function test_tebakan_pin_paralel_ke_owner_tetap_dibatasi(): void
    {
        [$device] = $this->tablet();
        $owner = $this->user('Owner');
        $lock = new AttemptLock('pos-pin:user:'.$owner->id);
        // Empat permintaan lain sedang berjalan untuk karyawan yang sama.
        foreach (range(1, 4) as $i) {
            $lock->take(5);
        }

        $codes = [];
        foreach (['0000', '0001', '0002'] as $pin) {
            try {
                app(PosLogin::class)->attempt($device, $owner->id, $pin);
            } catch (PosLoginException $e) {
                $codes[] = $e->reason;
            }
        }

        // Hanya satu yang benar-benar diverifikasi (jatah kelima), lalu terkunci.
        $this->assertSame([PosLoginException::LOCKED, PosLoginException::LOCKED, PosLoginException::LOCKED], $codes);
        $this->assertGreaterThan(0, app(PosLogin::class)->lockedFor($device, $owner->id));
    }

    public function test_pin_penyetuju_paralel_dibatasi_dan_berlipat(): void
    {
        [$device] = $this->tablet();
        $approval = app(Approval::class);
        $fail = function () use ($approval, $device) {
            try {
                $approval->verify($device, '0000', 'discount');
            } catch (PosLoginException $e) {
                return $e;
            }
        };

        foreach (range(1, 4) as $i) {
            $this->assertSame(PosLoginException::WRONG_PIN, $fail()->reason);
        }
        $this->assertSame(60, $fail()->seconds);

        $this->travel(61)->seconds();
        foreach (range(1, 4) as $i) {
            $fail();
        }
        $this->assertSame(120, $fail()->seconds);
    }

    public function test_kode_tablet_dibatasi_global_walau_ip_berganti(): void
    {
        config(['pos.pairing_global_max_attempts' => 6]);
        $login = app(PosLogin::class);
        $reasons = [];

        foreach (range(1, 7) as $i) {
            try {
                $login->pair('000000', '10.0.0.'.$i);
            } catch (PosLoginException $e) {
                $reasons[] = $e->reason;
            }
        }

        $this->assertSame(array_merge(array_fill(0, 5, PosLoginException::INVALID_CODE), [PosLoginException::LOCKED, PosLoginException::LOCKED]), $reasons);
    }
}
