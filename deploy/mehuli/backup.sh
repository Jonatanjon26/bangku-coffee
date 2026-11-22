#!/usr/bin/env bash
# Backup harian bangku.mehuli.id: pg_dump (format custom, konsisten tanpa mengunci kasir) + foto menu.
# Dipasang provision.sh ke /var/www/bangku/backup.sh; dijalankan cron (/etc/cron.d/bangku) sebagai user bangku.
# Kredensial: ~/.pgpass user bangku (dibuat provision.sh). Pulihkan: pg_restore --clean --if-exists -d bangku <file>.
set -euo pipefail

APP_DIR=/var/www/bangku
BACKUP_DIR=/var/backups/bangku
BACKUP_DAYS=30
STAMP=$(date +%Y%m%d-%H%M)

umask 077
mkdir -p "$BACKUP_DIR"
# Folder kerja pemanggil bisa tidak terbaca user bangku (mis. sudo dari home admin): find akan gagal.
cd "$BACKUP_DIR"

# Ditulis ke file sementara; diberi nama akhir hanya bila berhasil & bisa dibaca ulang.
TMP="$BACKUP_DIR/.db-$STAMP.dump.part"
trap 'rm -f "$TMP"' EXIT
pg_dump -h 127.0.0.1 -U bangku -d bangku --format=custom --no-owner --file="$TMP"
pg_restore --list "$TMP" > /dev/null
mv "$TMP" "$BACKUP_DIR/db-$STAMP.dump"
tar -czf "$BACKUP_DIR/foto-$STAMP.tar.gz" -C "$APP_DIR/shared/storage/app" public

find "$BACKUP_DIR" -name 'db-*.dump' -mtime +"$BACKUP_DAYS" -delete
find "$BACKUP_DIR" -name 'foto-*.tar.gz' -mtime +"$BACKUP_DAYS" -delete

echo "$(date '+%F %T') backup ok: db-$STAMP.dump ($(du -h "$BACKUP_DIR/db-$STAMP.dump" | cut -f1))"
