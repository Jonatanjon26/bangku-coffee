# Pengembangan di Windows

Dua cara. **Cara A (native)** paling sederhana dan cukup untuk sehari-hari: database SQLite, tanpa Docker.
**Cara B (Docker lewat WSL2)** bila ingin sama persis dengan Mac (dev-platform, PostgreSQL, `bangku.localhost`).

Kompatibilitas Windows diuji di CI (`.github/workflows/tests.yml`, job `windows-latest`).

## Cara A — native (disarankan)

### 1. Pasang perangkat

| Perlu | Versi | Cara mudah |
|---|---|---|
| PHP | **≥ 8.4.1** (sama dengan produksi) | [Laravel Herd for Windows](https://herd.laravel.com/windows) — sudah berisi PHP, Composer, Node. Atau `scoop install php composer nodejs-lts` |
| Composer | 2.x | ikut Herd / Scoop |
| Node.js | ≥ 22 | ikut Herd / Scoop / [nodejs.org](https://nodejs.org) |
| Git | terbaru | [git-scm.com](https://git-scm.com) (Git Bash ikut terpasang) |

Bila PHP dipasang manual (bukan Herd), aktifkan ekstensi di `php.ini`:

```ini
extension=curl
extension=fileinfo
extension=intl
extension=mbstring
extension=openssl
extension=pdo_sqlite
extension=sqlite3
extension=zip
; opsional, untuk menguji di PostgreSQL/MySQL:
;extension=pdo_pgsql
;extension=pdo_mysql
```

Cek: `php -v` (≥ 8.4.1) dan `php -m` (ekstensi di atas muncul).

### 2. Git: akhir baris

`.gitattributes` memaksa LF untuk semua file teks. Supaya Git di Windows tidak mengubahnya ke CRLF:

```powershell
git config --global core.autocrlf false
```

(Penting untuk `deploy/*.sh`: skrip dengan CRLF gagal di server.)

### 3. Ambil kode & pasang

```powershell
git clone https://github.com/mehuli-id/bangku-coffee.git
cd bangku-coffee
composer config --global github-oauth.github.com <TOKEN>   # sekali per komputer: akses baca mehuli-id/filament-shadcn
copy .env.example .env                                      # isi ADMIN_EMAIL / ADMIN_PASSWORD bila perlu
composer setup                                              # install, key, migrate, seed, npm build
```

Bawaan `.env.example` sudah SQLite (`database/database.sqlite`), tidak perlu server database.
`storage:link` di Windows membuat *junction*, tidak perlu hak administrator.

Data contoh 14 hari (opsional): `php artisan db:seed --class=DemoSeeder`.

### 4. Jalankan

```powershell
composer dev
```

Di Windows `composer dev` (= `php artisan dev`) menjalankan server, antrean, dan Vite lewat `concurrently`
(sudah ada di `package.json`). Log `pail` tidak ikut karena butuh ekstensi `pcntl` yang tidak ada di Windows —
lihat `storage/logs/laravel.log`. Berhenti: Ctrl+C.

- Admin: http://localhost:8000/admin
- Kasir: http://localhost:8000/masuk (kode tablet lokal 246810; PIN Rani 1111, Dimas 2222, Sinta 3333, Owner 9999)

Pengguna Herd: folder project di dalam folder *Herd paths* otomatis tersaji di `http://bangku-coffee.test`;
samakan `APP_URL` di `.env`.

### 5. Tes

```powershell
composer test
```

Uji mockup (Playwright):

```powershell
cd tests\mockup
npm install
npx playwright install chromium   # sekali per komputer
npm test
```

### Mencoba dari tablet di jaringan yang sama

```powershell
npm run build
php artisan serve --host=0.0.0.0 --port=8000
```

Buka `http://<IP-laptop>:8000/masuk` di tablet; izinkan PHP di Windows Defender Firewall (jaringan *Private*) bila diminta.
Cetak lewat **Web Bluetooth** hanya bisa di HTTPS atau `localhost` (aturan browser) — dari tablet lewat IP biasa pakai **RawBT**.

## Cara B — Docker lewat WSL2 (sama dengan Mac)

`compose.yaml` memakai dev-platform (`../../_platform/dev-platform`) yang berisi `Makefile` dan skrip bash,
jadi jalankan dari **WSL2 (Ubuntu)**, bukan PowerShell.

1. Pasang WSL2 (`wsl --install -d Ubuntu`) dan Docker Desktop dengan *WSL integration* untuk Ubuntu.
2. Di Ubuntu: `sudo apt install make git`.
3. Simpan kode **di dalam filesystem WSL** (mis. `~/kode/...`), bukan di `/mnt/c/...` — jauh lebih cepat dan
   perubahan file terdeteksi Vite. Susunan folder sama seperti di Mac supaya `../../_platform/dev-platform` ketemu.
4. Ikuti `compose.yaml` & README dev-platform: `make -C ../../_platform/dev-platform init up`, lalu `docker compose up -d`.
5. Browser Windows bisa membuka `http://bangku.localhost/admin` (`*.localhost` mengarah ke 127.0.0.1 di Chrome/Edge).

Editor: VS Code dengan ekstensi *WSL* (`code .` dari Ubuntu).

## Masalah umum

| Gejala | Penyebab / solusi |
|---|---|
| `could not find driver` | `pdo_sqlite` belum aktif di `php.ini`. |
| `ext-intl` / `ext-zip` missing saat `composer install` | Aktifkan `intl` / `zip` di `php.ini`. |
| Composer minta token / 404 `mehuli/filament-shadcn` | Token GitHub belum diset (langkah 3). |
| `Your lock file … requires php >=8.4.1` | PHP terlalu lama; pasang 8.4 terbaru. |
| `bash\r: No such file or directory` di server | File `.sh` ter-commit dengan CRLF; set `core.autocrlf false` lalu checkout ulang file itu. |
| Perubahan CSS/JS tidak muncul | `composer dev` (Vite) tidak berjalan dan `public/hot` tertinggal → jalankan lagi `composer dev` atau `npm run build`. |
