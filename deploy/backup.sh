#!/usr/bin/env bash
# Backup harian: database (mysqldump konsisten tanpa mengunci kasir) + foto menu. Disimpan BACKUP_DAYS hari.
# Cron (user bangku):  15 3 * * * /var/www/bangku/deploy/backup.sh >> /var/www/bangku/storage/logs/backup.log 2>&1
# Kredensial user backup (hanya-baca) dibaca dari ~/.my.cnf user ini (lihat docs/pasang-vps.md), bukan dari baris perintah.
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/bangku}"
BACKUP_DIR="${BACKUP_DIR:-/var/backups/bangku}"
BACKUP_DAYS="${BACKUP_DAYS:-30}"
DB_NAME="${DB_NAME:-$(grep '^DB_DATABASE=' "$APP_DIR/.env" | cut -d= -f2-)}"
STAMP=$(date +%Y%m%d-%H%M)

umask 077
mkdir -p "$BACKUP_DIR"
cd "$BACKUP_DIR"   # folder kerja pemanggil bisa tidak terbaca user ini

# --single-transaction: snapshot konsisten InnoDB tanpa menghentikan transaksi kasir. --triggers: trigger log audit ikut.
# --set-gtid-purged=OFF: bisa dipulihkan ke server mana pun (termasuk server yang sama).
# Ditulis ke file sementara; hanya diberi nama akhir bila dump & gzip berhasil (gagal → tidak ada file setengah jadi).
TMP="$BACKUP_DIR/.db-$STAMP.sql.gz.part"
trap 'rm -f "$TMP"' EXIT
mysqldump --single-transaction --routines --triggers --no-tablespaces --set-gtid-purged=OFF "$DB_NAME" | gzip -9 > "$TMP"
gzip -t "$TMP"
mv "$TMP" "$BACKUP_DIR/db-$STAMP.sql.gz"
tar -czf "$BACKUP_DIR/foto-$STAMP.tar.gz" -C "$APP_DIR/storage/app" public

find "$BACKUP_DIR" -name 'db-*.sql.gz' -mtime +"$BACKUP_DAYS" -delete
find "$BACKUP_DIR" -name 'foto-*.tar.gz' -mtime +"$BACKUP_DAYS" -delete

echo "$(date '+%F %T') backup ok: db-$STAMP.sql.gz ($(du -h "$BACKUP_DIR/db-$STAMP.sql.gz" | cut -f1))"

# Salinan di luar VPS (sangat disarankan): pasang rclone lalu buka komentar baris di bawah.
# rclone copy "$BACKUP_DIR" "remote:bangku-backup" --max-age 2d
