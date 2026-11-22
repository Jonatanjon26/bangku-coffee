#!/usr/bin/env bash
# =============================================================================
# Deploy bangku.mehuli.id tanpa downtime (pola sama dengan /var/www/mehuli/deploy.sh).
# Dipasang provision.sh ke /var/www/bangku/deploy.sh. Jalankan di server sebagai user bangku:
#   sudo -u bangku -H bash /var/www/bangku/deploy.sh            # rilis terbaru dari branch main
#   sudo -u bangku -H bash /var/www/bangku/deploy.sh <commit>   # rilis commit tertentu
#   sudo -u bangku -H bash /var/www/bangku/deploy.sh rollback   # kembali ke rilis sebelumnya
#
# Struktur: /var/www/bangku/{releases/<waktu>, shared/{.env,storage}, current -> releases/<waktu>}
# Database kosong (pemasangan pertama) → data awal diisi (pengaturan, menu contoh, peran, akun owner).
# =============================================================================
set -euo pipefail

APP_DIR=/var/www/bangku
REPO=git@github.com:mehuli-id/bangku-coffee.git
KEEP=5
export COMPOSER_NO_INTERACTION=1

[ "$(whoami)" = "bangku" ] || { echo "Jalankan sebagai user bangku." >&2; exit 1; }
cd "$APP_DIR"

reload() { sudo /usr/bin/systemctl reload php8.4-fpm; }

if [ "${1:-}" = "rollback" ]; then
    CURRENT="$(readlink -f current)"
    PREVIOUS="$(ls -1d releases/* | sort | grep -v "$(basename "$CURRENT")" | tail -1)"
    [ -n "$PREVIOUS" ] || { echo "Tidak ada rilis sebelumnya." >&2; exit 1; }
    ln -sfn "$APP_DIR/$PREVIOUS" current.tmp && mv -T current.tmp current
    reload
    echo "Rollback ke $PREVIOUS (sebelumnya $(basename "$CURRENT")). Migrasi database tidak dibatalkan."
    exit 0
fi

grep -q '^APP_ENV=production' shared/.env || { echo "shared/.env: APP_ENV harus production." >&2; exit 1; }
grep -q '^APP_DEBUG=false' shared/.env || { echo "shared/.env: APP_DEBUG harus false." >&2; exit 1; }

REF="${1:-main}"
RELEASE="$APP_DIR/releases/$(date +%Y%m%d%H%M%S)"
echo "== Rilis $RELEASE ($REF)"
# Rilis yang gagal di tengah dihapus; rilis aktif (current) tidak tersentuh.
trap 'status=$?; if [ $status -ne 0 ]; then rm -rf "$RELEASE"; echo "GAGAL — rilis aktif tetap $(basename "$(readlink -f "$APP_DIR/current" 2>/dev/null || echo -)")." >&2; fi' EXIT

git clone --quiet --filter=blob:none "$REPO" "$RELEASE"
git -C "$RELEASE" checkout --quiet "$REF"
git -C "$RELEASE" rev-parse --short HEAD > "$RELEASE/REVISION"
rm -rf "$RELEASE/.git"

# Berkas bersama antar-rilis.
rm -rf "$RELEASE/storage"
ln -s "$APP_DIR/shared/storage" "$RELEASE/storage"
ln -s "$APP_DIR/shared/.env" "$RELEASE/.env"

cd "$RELEASE"
composer install --no-dev --optimize-autoloader --no-progress --quiet
npm ci --no-audit --no-fund --loglevel=error --ignore-scripts
npm run build --silent
rm -rf node_modules

if ! grep -q '^APP_KEY=base64:' "$APP_DIR/shared/.env"; then
    php artisan key:generate --force --quiet
fi

STATE=$(php artisan tinker --execute='echo Schema::hasTable("migrations") ? "installed" : "fresh";' 2>/dev/null | tail -1)
case "$STATE" in fresh|installed) echo "== Database: $STATE" ;; *) echo "Tidak bisa terhubung ke database (cek DB_* di shared/.env)." >&2; exit 1 ;; esac

php artisan migrate --force
if [ "$STATE" = "fresh" ]; then
    php artisan db:seed --force
fi

php artisan storage:link --force --quiet
php artisan optimize
php artisan filament:optimize --quiet || true

# Aktifkan rilis secara atomik, lalu muat ulang PHP-FPM (opcache).
ln -sfn "$RELEASE" "$APP_DIR/current.tmp" && mv -T "$APP_DIR/current.tmp" "$APP_DIR/current"
trap - EXIT   # rilis sudah aktif: jangan pernah dihapus oleh penanganan gagal
reload

# Simpan $KEEP rilis terakhir.
cd "$APP_DIR/releases"
ls -1d */ | sort | head -n -"$KEEP" | xargs -r rm -rf

echo "== Selesai: $(cat "$RELEASE/REVISION") aktif."
if [ "$STATE" = "fresh" ]; then
    echo "PENTING: hapus nilai ADMIN_PASSWORD dari $APP_DIR/shared/.env, lalu: cd $APP_DIR/current && php artisan config:cache"
fi
