#!/usr/bin/env bash
# Pasang pertama kali atau perbarui Bangku Coffee POS di server. Jalankan dari folder aplikasi sebagai user web:
#   cd /var/www/bangku && sudo -u bangku bash deploy/deploy.sh
# Kode sudah harus ada di folder ini (git pull / rsync) dan .env sudah diisi (lihat deploy/env.production.example).
# Aman diulang: "pasang pertama" ditentukan dari database (belum ada tabel migrasi), bukan dari langkah yang sudah jalan.
set -euo pipefail
cd "$(dirname "$0")/.."

say() { printf '\n\033[1m== %s\033[0m\n' "$1"; }
fail() { printf '\n\033[31mGAGAL: %s\033[0m\n' "$1" >&2; exit 1; }

[ -f .env ] || fail ".env belum ada. Salin dari deploy/env.production.example lalu isi."
grep -q '^APP_ENV=production' .env || fail "APP_ENV di .env harus production."
grep -q '^APP_DEBUG=false' .env || fail "APP_DEBUG di .env harus false."
php -r 'exit(version_compare(PHP_VERSION, "8.4.1", ">=") ? 0 : 1);' || fail "Butuh PHP 8.4.1 atau lebih baru (sekarang $(php -r 'echo PHP_VERSION;'))."

# Status database lewat aplikasi: "fresh" (belum dipasang), "installed", "trigger-blocked", atau gagal terhubung.
db_state() {
    php artisan tinker --execute='
        $c = DB::connection();
        $c->getPdo();
        if (in_array($c->getDriverName(), ["mysql", "mariadb"])
            && (int) $c->selectOne("SELECT @@log_bin AS b")->b
            && ! (int) $c->selectOne("SELECT @@log_bin_trust_function_creators AS t")->t) { echo "trigger-blocked"; return; }
        echo Schema::hasTable("migrations") ? "installed" : "fresh";' 2>/dev/null | tail -1
}

MAINTENANCE=0
# Perbarui aplikasi yang sudah berjalan: masuk mode perawatan sebelum paket/aset diganti.
if [ -f vendor/autoload.php ] && grep -q '^APP_KEY=base64:' .env && [ "$(db_state || true)" = "installed" ]; then
    say "Mode perawatan (kasir menampilkan halaman perawatan sebentar)"
    php artisan down --retry=15
    MAINTENANCE=1
fi
# Gagal di tengah: aplikasi sengaja tetap dalam mode perawatan (bukan dinyalakan dalam keadaan setengah jadi).
trap 'status=$?; if [ $status -ne 0 ] && [ "$MAINTENANCE" = 1 ]; then echo "Aplikasi masih mode perawatan. Perbaiki lalu: php artisan up" >&2; fi' EXIT

say "Paket PHP (tanpa paket pengembangan)"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

say "Aset (CSS/JS)"
npm ci --ignore-scripts --no-audit --no-fund
npm run build
rm -rf node_modules

if ! grep -q '^APP_KEY=base64:' .env; then
    say "Kunci aplikasi"
    php artisan key:generate --force
fi

say "Cek database"
php artisan config:clear >/dev/null
STATE=$(db_state) || true
case "$STATE" in
    fresh|installed) echo "Database: $STATE" ;;
    trigger-blocked) fail "MySQL: aktifkan log_bin_trust_function_creators = 1 (deploy/mysql-bangku.cnf) lalu restart MySQL. Tanpa ini trigger log audit tidak bisa dibuat." ;;
    *) fail "Tidak bisa terhubung ke database. Cek DB_* di .env." ;;
esac

say "Migrasi database"
php artisan migrate --force

if [ "$STATE" = "fresh" ]; then
    say "Data awal (pengaturan, menu contoh, peran, akun owner)"
    php artisan db:seed --force
fi

[ -L public/storage ] || php artisan storage:link

say "Cache konfigurasi, rute, tampilan"
php artisan optimize:clear >/dev/null
php artisan optimize
php artisan filament:optimize

if [ "$MAINTENANCE" = 1 ]; then
    php artisan up
fi

say "Selesai"
if [ "$STATE" = "fresh" ]; then
    echo "Masuk panel: $(grep '^APP_URL=' .env | cut -d= -f2-)/$(grep '^ADMIN_PANEL_PATH=' .env | cut -d= -f2-)"
    echo "PENTING: hapus nilai ADMIN_PASSWORD dari .env sekarang, lalu jalankan: php artisan config:cache"
fi
