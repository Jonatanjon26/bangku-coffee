#!/usr/bin/env bash
# =============================================================================
# Persiapan server mehuli untuk bangku.mehuli.id (sekali; aman diulang). Jalankan sebagai root:
#   sudo bash deploy/mehuli/provision.sh
# Tidak menyentuh aplikasi lain di server (mehuli.id, SLB Budidaya): user, database, pool PHP-FPM,
# situs Nginx, sudoers & cron milik bangku sendiri. Konfigurasi Nginx/PHP-FPM dicek sebelum di-reload.
# =============================================================================
set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)"
APP=bangku
APP_DIR=/var/www/bangku
DOMAIN=bangku.mehuli.id
export LC_ALL=C.UTF-8

[ "$(id -u)" = 0 ] || { echo "Jalankan dengan sudo." >&2; exit 1; }
say() { printf '\n== %s\n' "$1"; }
as_app() { sudo -u "$APP" -H "$@"; }

say "User sistem $APP"
id "$APP" >/dev/null 2>&1 || adduser --system --group --home "$APP_DIR" --shell /bin/bash "$APP"
chmod 750 "$APP_DIR"
usermod -aG "$APP" www-data   # Nginx membaca public/ (folder aplikasi 750)

say "Folder"
install -d -o "$APP" -g "$APP" -m 750 "$APP_DIR/releases" "$APP_DIR/shared"
for d in app/public framework/cache/data framework/sessions framework/views logs; do
    install -d -o "$APP" -g "$APP" -m 750 "$APP_DIR/shared/storage/$d"
done
install -d -o "$APP" -g "$APP" -m 750 /var/log/bangku
install -d -o "$APP" -g "$APP" -m 700 /var/backups/bangku

say "PostgreSQL: role & database $APP"
if ! sudo -u postgres psql -Atc "SELECT 1 FROM pg_roles WHERE rolname='$APP'" | grep -q 1; then
    DB_PASSWORD="$(openssl rand -base64 33 | tr -d '/+=' | cut -c1-40)"
    sudo -u postgres psql -q -c "CREATE ROLE $APP LOGIN PASSWORD '$DB_PASSWORD'"
else
    DB_PASSWORD="$(grep '^DB_PASSWORD=' "$APP_DIR/shared/.env" 2>/dev/null | cut -d= -f2- || true)"
    [ -n "$DB_PASSWORD" ] || { echo "Role $APP sudah ada tetapi shared/.env tidak berisi DB_PASSWORD." >&2; exit 1; }
fi
sudo -u postgres psql -Atc "SELECT 1 FROM pg_database WHERE datname='$APP'" | grep -q 1 \
    || sudo -u postgres psql -q -c "CREATE DATABASE $APP OWNER $APP ENCODING 'UTF8' TEMPLATE template0"
sudo -u postgres psql -q -c "REVOKE ALL ON DATABASE $APP FROM PUBLIC"
as_app bash -c "umask 077; printf '127.0.0.1:5432:$APP:$APP:%s\n' '$DB_PASSWORD' > ~/.pgpass"

say "shared/.env"
ADMIN_PASSWORD=""
if [ ! -f "$APP_DIR/shared/.env" ]; then
    ADMIN_PASSWORD="$(openssl rand -base64 24 | tr -d '/+=' | cut -c1-20)"
    sed -e "s|^APP_URL=.*|APP_URL=https://$DOMAIN|" \
        -e "s|^DB_CONNECTION=.*|DB_CONNECTION=pgsql|" \
        -e "s|^DB_HOST=.*|DB_HOST=127.0.0.1|" \
        -e "s|^DB_PORT=.*|DB_PORT=5432|" \
        -e "s|^DB_DATABASE=.*|DB_DATABASE=$APP|" \
        -e "s|^DB_USERNAME=.*|DB_USERNAME=$APP|" \
        -e "s|^DB_PASSWORD=.*|DB_PASSWORD=$DB_PASSWORD|" \
        -e "s|^ADMIN_EMAIL=.*|ADMIN_EMAIL=owner@bangkucoffee.id|" \
        -e "s|^ADMIN_PASSWORD=.*|ADMIN_PASSWORD=$ADMIN_PASSWORD|" \
        -e "/^# localhost = socket MySQL/d" \
        "$SRC/../env.production.example" > "$APP_DIR/shared/.env"
    chown "$APP:$APP" "$APP_DIR/shared/.env"
    chmod 600 "$APP_DIR/shared/.env"
fi

say "Akses GitHub (deploy key hanya-baca per repo)"
as_app bash -c '
    set -e; umask 077; mkdir -p ~/.ssh
    [ -f ~/.ssh/github_bangku ] || ssh-keygen -q -t ed25519 -N "" -C "bangku@mehuli-server (bangku-coffee)" -f ~/.ssh/github_bangku
    [ -f ~/.ssh/github_shadcn ] || ssh-keygen -q -t ed25519 -N "" -C "bangku@mehuli-server (filament-shadcn)" -f ~/.ssh/github_shadcn
    cat > ~/.ssh/config <<CFG
Host github.com
    IdentityFile ~/.ssh/github_bangku
    IdentitiesOnly yes
Host github-shadcn
    HostName github.com
    IdentityFile ~/.ssh/github_shadcn
    IdentitiesOnly yes
CFG
    grep -q "^github.com " ~/.ssh/known_hosts 2>/dev/null || ssh-keyscan -t ed25519,rsa github.com >> ~/.ssh/known_hosts 2>/dev/null
    # Paket tema privat memakai key-nya sendiri (deploy key GitHub berlaku untuk satu repo).
    git config --global url."git@github-shadcn:mehuli-id/filament-shadcn".insteadOf "git@github.com:mehuli-id/filament-shadcn"
    git config --global --add url."git@github-shadcn:mehuli-id/filament-shadcn".insteadOf "https://github.com/mehuli-id/filament-shadcn"
    composer config --global preferred-install.mehuli/* source >/dev/null
'

say "Skrip deploy & backup"
install -o "$APP" -g "$APP" -m 750 "$SRC/deploy.sh" "$APP_DIR/deploy.sh"
install -o "$APP" -g "$APP" -m 750 "$SRC/backup.sh" "$APP_DIR/backup.sh"

say "PHP-FPM pool"
install -o root -g root -m 644 "$SRC/php-fpm-bangku.conf" /etc/php/8.4/fpm/pool.d/bangku.conf
# Konfigurasi salah tidak boleh tertinggal (reload berikutnya akan menjatuhkan pool aplikasi lain).
php-fpm8.4 -t 2>/dev/null || { rm -f /etc/php/8.4/fpm/pool.d/bangku.conf; php-fpm8.4 -t; exit 1; }
systemctl reload php8.4-fpm

say "Sudoers & cron"
install -o root -g root -m 440 "$SRC/sudoers-bangku" /tmp/sudoers-bangku.check
visudo -cf /tmp/sudoers-bangku.check >/dev/null && mv /tmp/sudoers-bangku.check /etc/sudoers.d/bangku
install -o root -g root -m 644 "$SRC/cron-bangku" /etc/cron.d/bangku

say "Nginx"
install -o root -g root -m 644 "$SRC/nginx-bangku.conf" /etc/nginx/sites-available/bangku.conf
ln -sfn /etc/nginx/sites-available/bangku.conf /etc/nginx/sites-enabled/bangku.conf
nginx -t 2>/dev/null || { rm -f /etc/nginx/sites-enabled/bangku.conf; nginx -t; exit 1; }
systemctl reload nginx

say "Selesai"
echo "Deploy key (tambahkan sebagai Deploy key hanya-baca di GitHub):"
echo "  mehuli-id/bangku-coffee : $(cat "$APP_DIR/.ssh/github_bangku.pub")"
echo "  mehuli-id/filament-shadcn: $(cat "$APP_DIR/.ssh/github_shadcn.pub")"
[ -n "$ADMIN_PASSWORD" ] && echo "Akun owner awal: owner@bangkucoffee.id / $ADMIN_PASSWORD (ganti setelah masuk)"
echo "Lalu: sudo -u $APP -H bash $APP_DIR/deploy.sh"
