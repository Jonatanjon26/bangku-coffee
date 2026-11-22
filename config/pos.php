<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Nilai bawaan pengaturan toko
    |--------------------------------------------------------------------------
    |
    | Hanya dipakai bila kunci belum ada di tabel `settings` (dan untuk mengisi tabel
    | saat seeding pertama). Nilai yang berlaku diubah lewat panel admin › Pengaturan.
    | Sumber: docs/mockup/shared/config.js (DEFAULT_CONFIG).
    |
    */

    'settings' => [
        'store' => [
            'name' => 'Bangku Coffee',
            'address' => 'Jl. Contoh No. 1, Medan',
            'phone' => '0812-0000-0000',
            'footer' => 'Terima kasih, sampai ketemu lagi!',
        ],
        // Service dihitung dari (subtotal − diskon); PB1 dari (subtotal − diskon + service).
        'charges' => [
            'service_enabled' => false,
            'service_rate' => 5,
            'service_label' => 'Service',
            'tax_enabled' => false,
            'tax_rate' => 10,
            'tax_label' => 'PB1',
        ],
        'discounts' => [
            'approval_above_pct' => 20,
        ],
        'policy' => [
            'variance_approval_above' => 20000,
            'cash_out_approval_above' => 100000,
            // Total kas keluar per shift di atas ini perlu persetujuan (0 = tanpa batas per shift).
            'cash_out_shift_approval_above' => 300000,
            'refund_needs_approval' => true,
            'carry_open_bills' => true,
            'open_bill_day_approval' => true,
            'open_bill_max_days' => 3,
            'require_customer_name' => true,
            'pin_length' => 4,
            'max_pin_attempts' => 5,
            // Batas salah PIN per tablet (semua karyawan) sebelum tablet dikunci sementara.
            'max_pin_attempts_device' => 15,
            'lock_seconds' => 60,
            'auto_lock_minutes' => 5,
        ],
        'customers' => [
            'enabled' => true,
            'ask_phone' => true,
            'loyalty_enabled' => false,
            'point_per' => 10000,
            // Penukaran: nilai 1 poin (Rp) & minimal poin sekali tukar.
            'point_value' => 100,
            'redeem_min_points' => 50,
        ],
        'printing' => [
            'cols' => 32,
            'ticket' => true,
            'receipt_default' => true,
        ],
        'devices' => [
            // Masa berlaku kode pendaftaran tablet (jam).
            'pairing_code_hours' => 24,
        ],
        'orders' => [
            // Kode pesanan: {prefix}-{MMDD}-{antrean 3 digit}, mis. B-1019-007.
            'code_prefix' => 'B',
            // Jam pergantian hari dagang (0–12): transaksi sebelum jam ini masuk hari sebelumnya (buka lewat 00.00).
            'day_change_hour' => 4,
        ],
    ],

    /*
    | Cookie token tablet terdaftar (terenkripsi, HttpOnly). Token asli hanya di cookie;
    | server menyimpan hash-nya di tabel devices dan bisa mencabutnya dari admin.
    */
    'device_cookie' => [
        'name' => env('POS_DEVICE_COOKIE', 'bangku_device'),
        'days' => (int) env('POS_DEVICE_COOKIE_DAYS', 1825),
    ],

    // Hitungan salah PIN/kode direset bila tidak ada percobaan salah selama ini (detik).
    'attempt_window_seconds' => (int) env('POS_ATTEMPT_WINDOW_SECONDS', 3600),

    // Kunci bertingkat: setiap kali terkunci lagi dalam jendela ini, lama kunci berlipat (maks. lock_max_seconds).
    'lock_escalation_window_seconds' => (int) env('POS_LOCK_ESCALATION_WINDOW_SECONDS', 86400),
    'lock_max_seconds' => (int) env('POS_LOCK_MAX_SECONDS', 3600),

    // Cari/pilih pelanggan di kasir per kasir per menit (data pribadi tidak bisa dikuras).
    'customer_lookups_per_minute' => (int) env('POS_CUSTOMER_LOOKUPS_PER_MINUTE', 60),
    'customer_lookups_per_day' => (int) env('POS_CUSTOMER_LOOKUPS_PER_DAY', 600),

    // Pengecekan "PIN sudah dipakai" di panel admin per pengguna per jendela (mencegah menebak PIN penyetuju).
    'admin_pin_checks_max' => (int) env('POS_ADMIN_PIN_CHECKS_MAX', 10),

    // Ganti PIN sendiri di kasir: jawaban "PIN tidak bisa dipakai" per karyawan per jendela (tablet: 2×), dan
    // jumlah ganti PIN yang berhasil per karyawan per jendela. Penghitung ini tidak direset oleh ganti PIN yang berhasil.
    'pin_change_window_seconds' => (int) env('POS_PIN_CHANGE_WINDOW_SECONDS', 86400),
    'pin_taken_max_per_day' => (int) env('POS_PIN_TAKEN_MAX_PER_DAY', 3),
    'pin_changes_max_per_day' => (int) env('POS_PIN_CHANGES_MAX_PER_DAY', 3),

    // Batas salah kode pendaftaran tablet dari semua IP digabung (selain batas per IP).
    'pairing_global_max_attempts' => (int) env('POS_PAIRING_GLOBAL_MAX_ATTEMPTS', 20),

    // Lama cache pengaturan (detik). Cache dihapus setiap kali pengaturan diubah.
    'settings_cache_ttl' => (int) env('POS_SETTINGS_CACHE_TTL', 3600),

];
