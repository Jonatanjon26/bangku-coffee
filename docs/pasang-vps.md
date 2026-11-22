# Pasang Bangku Coffee POS di VPS (Biznet Gio)

Panduan untuk satu VPS **Ubuntu 24.04 LTS**: Nginx + PHP 8.4 + MySQL 8, HTTPS dari Let's Encrypt.
Semua perintah dijalankan lewat SSH sebagai user dengan `sudo`. Ganti `kasir.contoh.id` dengan domain Anda.

Ukuran VPS: 2 vCPU / 2 GB RAM / 40 GB SSD sudah cukup untuk satu toko dengan beberapa tablet.
Pilih lokasi data center Indonesia (Jakarta) supaya kasir terasa cepat.

> Kasir butuh koneksi ke VPS. Siapkan cadangan internet di toko (tablet dengan SIM data, atau hotspot/MiFi)
> dan prosedur darurat (catat di kertas, input belakangan bila internet benar-benar mati).

---

## 1. Domain & DNS

Buat record **A** untuk `kasir.contoh.id` yang mengarah ke IP publik VPS. Tunggu sampai `ping kasir.contoh.id`
menjawab dengan IP VPS. HTTPS wajib: tanpa HTTPS, cookie sesi produksi tidak terkirim dan cetak lewat
Web Bluetooth tidak tersedia (RawBT tetap bisa).

## 2. Siapkan server

```bash
sudo apt update && sudo apt -y upgrade
sudo timedatectl set-timezone Asia/Jakarta

# Firewall: hanya SSH dan web
sudo ufw allow OpenSSH && sudo ufw allow 'Nginx Full' && sudo ufw --force enable

# PHP 8.4 (Ubuntu 24.04 bawaannya 8.3; aplikasi butuh ≥ 8.4.1)
sudo add-apt-repository -y ppa:ondrej/php
sudo apt update
sudo apt -y install nginx mysql-server unzip git certbot python3-certbot-nginx \
  php8.4-fpm php8.4-cli php8.4-mysql php8.4-mbstring php8.4-xml php8.4-curl php8.4-zip \
  php8.4-intl php8.4-bcmath php8.4-gd php8.4-sqlite3

# Composer
curl -sS https://getcomposer.org/installer | sudo php -- --install-dir=/usr/local/bin --filename=composer

# Node.js 22 (hanya untuk membangun CSS/JS saat pasang/update)
curl -fsSL https://deb.nodesource.com/setup_22.x | sudo -E bash -
sudo apt -y install nodejs
```

Batasi unggahan PHP sesuai aplikasi (foto menu maks. 2 MB):

```bash
sudo sed -i 's/^upload_max_filesize.*/upload_max_filesize = 4M/; s/^post_max_size.*/post_max_size = 8M/; s/^expose_php.*/expose_php = Off/' /etc/php/8.4/fpm/php.ini
sudo systemctl restart php8.4-fpm
```

## 3. MySQL

```bash
sudo mysql_secure_installation          # jawab Y untuk semua (hapus user anonim, database test, login root jarak jauh)
sudo cp deploy/mysql-bangku.cnf /etc/mysql/mysql.conf.d/zz-bangku.cnf   # setelah kode ada di server (langkah 4), atau salin isinya manual
sudo systemctl restart mysql
```

`deploy/mysql-bangku.cnf` berisi `log_bin_trust_function_creators = 1`. **Wajib**: migrasi membuat trigger yang
mengunci log audit (tidak bisa diubah/dihapus, bahkan oleh user aplikasi). Tanpa pengaturan ini MySQL menolak
(error 1419) dan skrip pasang berhenti dengan pesan yang menjelaskannya.

Buat database & user aplikasi (ganti sandi dengan yang panjang & acak, mis. dari `openssl rand -base64 24`):

```bash
sudo mysql <<'SQL'
CREATE DATABASE bangku CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'bangku'@'localhost' IDENTIFIED BY 'GANTI-SANDI-DATABASE';
GRANT ALL PRIVILEGES ON bangku.* TO 'bangku'@'localhost';
SQL
```

## 4. Kode aplikasi

Aplikasi berjalan sebagai user `bangku` (bukan root), di `/var/www/bangku`.

```bash
sudo adduser --system --group --home /var/www/bangku --shell /bin/bash bangku
sudo usermod -aG bangku www-data
```

Salin kode ke server, pilih salah satu:

- **Git (disarankan):** repo privat, lalu `sudo -u bangku git clone <url-repo> /var/www/bangku` dengan deploy key
  hanya-baca.
- **Tanpa repo:** dari komputer pengembang:
  `rsync -az --exclude vendor --exclude node_modules --exclude .env --exclude .git ./ user@VPS:/tmp/bangku/`
  lalu di server `sudo rsync -a /tmp/bangku/ /var/www/bangku/ && sudo chown -R bangku:bangku /var/www/bangku`.

Paket tema `mehuli/filament-shadcn` ada di repo GitHub privat. Beri composer token GitHub **hanya-baca**
(fine-grained token, akses *Contents: read* ke repo itu saja):

```bash
sudo -u bangku composer config --global github-oauth.github.com <TOKEN>
```

## 5. `.env` produksi

```bash
cd /var/www/bangku
sudo -u bangku cp deploy/env.production.example .env
sudo -u bangku nano .env        # isi APP_URL, DB_PASSWORD, ADMIN_EMAIL, ADMIN_PASSWORD
sudo chmod 640 .env
```

Penting di `.env`: `APP_ENV=production`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`. Skrip pasang menolak
berjalan bila dua yang pertama salah.

## 6. Pasang

```bash
cd /var/www/bangku
sudo -u bangku bash deploy/deploy.sh
```

Skrip ini (aman diulang) memasang paket tanpa paket pengembangan, membangun CSS/JS, membuat kunci aplikasi,
mengecek database, migrasi, mengisi data awal **hanya pada database kosong** (pengaturan, menu contoh, peran,
akun owner — tanpa karyawan/PIN/tablet contoh), `storage:link`, dan cache.

Setelah selesai, **hapus nilai `ADMIN_PASSWORD` dari `.env`** lalu `sudo -u bangku php artisan config:cache`.

Izin folder yang ditulis aplikasi:

```bash
sudo chown -R bangku:bangku /var/www/bangku
sudo chmod -R ug+rwX /var/www/bangku/storage /var/www/bangku/bootstrap/cache
```

PHP-FPM berjalan sebagai user `bangku`:

```bash
sudo sed -i 's/^user = www-data/user = bangku/; s/^group = www-data/group = bangku/' /etc/php/8.4/fpm/pool.d/www.conf
sudo systemctl restart php8.4-fpm
```

## 7. Nginx & HTTPS

```bash
sudo cp deploy/nginx-bangku.conf /etc/nginx/sites-available/bangku
sudo sed -i 's/kasir.contoh.id/<domain-anda>/' /etc/nginx/sites-available/bangku
sudo ln -s /etc/nginx/sites-available/bangku /etc/nginx/sites-enabled/
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx

sudo certbot --nginx -d <domain-anda> --redirect -m <email-owner> --agree-tos -n
```

Certbot memperpanjang sertifikat otomatis. Konfigurasi Nginx sudah memasang `nosniff` untuk foto menu dan
tidak pernah menjalankan PHP dari folder unggahan.

Nginx langsung menerima koneksi dari internet, jadi **jangan** mengaktifkan "trust all proxies" di aplikasi.
Bila kelak memakai Cloudflare/load balancer, atur proxy tepercaya hanya untuk IP mereka (`bootstrap/app.php`,
`trustProxies(at: [...])`); kalau tidak, batas salah PIN per IP bisa diakali dengan header palsu.

## 8. Backup harian

Backup memakai user database **terpisah yang hanya bisa membaca** (`mysqldump` di MySQL 8 butuh hak global
`PROCESS` & `FLUSH_TABLES`; hak itu tidak diberikan ke user aplikasi):

```bash
sudo mysql <<'SQL'
CREATE USER 'bangku_backup'@'localhost' IDENTIFIED BY 'GANTI-SANDI-BACKUP';
GRANT SELECT, SHOW VIEW, TRIGGER, LOCK TABLES ON bangku.* TO 'bangku_backup'@'localhost';
GRANT PROCESS, FLUSH_TABLES ON *.* TO 'bangku_backup'@'localhost';
SQL
```

Kredensialnya disimpan di file milik user `bangku` (bukan di baris perintah):

```bash
sudo -u bangku bash -c 'umask 077; printf "[client]\nuser=bangku_backup\npassword=GANTI-SANDI-BACKUP\n" > ~/.my.cnf'
sudo mkdir -p /var/backups/bangku && sudo chown bangku:bangku /var/backups/bangku && sudo chmod 700 /var/backups/bangku
sudo -u bangku /var/www/bangku/deploy/backup.sh          # uji sekali
sudo -u bangku crontab -e
# tambahkan:
15 3 * * * /var/www/bangku/deploy/backup.sh >> /var/www/bangku/storage/logs/backup.log 2>&1
```

Backup disimpan 30 hari di `/var/backups/bangku`. **Simpan juga salinan di luar VPS** (mis. rclone ke Google
Drive/penyimpanan objek — baris contoh ada di akhir `deploy/backup.sh`), dan aktifkan snapshot VPS dari panel
Biznet Gio bila tersedia.

Memulihkan backup (sudah diuji: data, akun, dan trigger log audit kembali utuh):

```bash
gunzip -c /var/backups/bangku/db-YYYYMMDD-HHMM.sql.gz | sudo mysql bangku
sudo -u bangku tar -xzf /var/backups/bangku/foto-YYYYMMDD-HHMM.tar.gz -C /var/www/bangku/storage/app
```

## 9. Setelah pasang (di panel admin)

1. Masuk `https://<domain-anda>/admin` dengan akun owner.
2. **Pengaturan › Toko**: nama, alamat, telepon, catatan struk.
3. **Menu, Kategori, Varian**: ganti menu contoh dengan menu toko.
4. **Peran & Karyawan**: buat karyawan dan PIN. Pertimbangkan **Pengaturan › Aturan › Panjang PIN = 6 digit**
   (jauh lebih sulit ditebak; ubah sebelum membuat PIN karyawan).
5. **Pengaturan › Aturan**: batas persetujuan (diskon, kas keluar per transaksi & per shift, selisih kas).
6. **Pengaturan › Perangkat › Buat kode**: buka `https://<domain-anda>/masuk` di tiap tablet, masukkan kodenya.
7. Di tablet: hubungkan printer RPP02 (RawBT dari Play Store untuk Bluetooth biasa), lalu **Tes printer**.

## 10. Update aplikasi

```bash
cd /var/www/bangku
sudo -u bangku git pull                 # atau rsync seperti langkah 4
sudo -u bangku bash deploy/deploy.sh
```

Saat update, kasir melihat halaman perawatan beberapa saat (lakukan di luar jam ramai). Bila update gagal di
tengah, aplikasi sengaja tetap dalam mode perawatan; perbaiki penyebabnya lalu `sudo -u bangku php artisan up`.

## Pemeriksaan cepat

- `https://<domain-anda>/masuk` menampilkan "Daftarkan tablet ini" di tablet baru.
- `curl -I https://<domain-anda>/masuk` memuat `Strict-Transport-Security`, `X-Frame-Options`, `X-Content-Type-Options`.
- `https://<domain-anda>/.env` → 404.
- Log aplikasi: `/var/www/bangku/storage/logs/laravel-*.log`; log backup: `storage/logs/backup.log`.
