# Bangku Coffee — POS

Aplikasi kasir (point of sale) untuk Bangku Coffee. Mockup (kasir tablet + panel admin) sudah disepakati; aplikasi Laravel sedang dibangun (lihat bagian *Aplikasi*).

## Mockup

Buka langsung di browser, tanpa internet dan tanpa server:

| File | Isi |
|---|---|
| `docs/mockup/masuk.html` | Masuk kasir di tablet: daftar tablet (kode sekali pakai dari admin) → pilih kasir → PIN. Salah PIN berulang → dikunci sementara. |
| `docs/mockup/kasir.html` | Layar kasir (perlu masuk dulu). Tombol 🔒 = kunci/ganti kasir; kunci otomatis saat tidak dipakai. |
| `docs/mockup/admin.html` | Panel admin (email + kata sandi): dasbor, transaksi & refund, shift & setoran, laporan, menu, kategori & varian, pelanggan, karyawan & peran, pengaturan. |
| `docs/mockup/shared/config.js` | Konfigurasi & data bersama. **Tidak ada daftar/aturan yang ditulis mati di kasir**: semua dibaca dari sini dan diubah lewat admin. |

Akun contoh: owner@bangkucoffee.id / password (owner), sinta@bangkucoffee.id / password (kepala toko). Kode daftar tablet: 246810.

### Yang diatur di panel admin

- **Menu**: nama, kategori, harga, ikon, grup varian, terlaris, tersedia/habis, tampil di kasir.
- **Kategori & varian**: kategori + stasiun (Bar/Dapur); grup varian dengan pilihan & tambahan harga, "selalu cetak" di tiket.
- **Pajak & service**: service charge (%) per jenis pesanan, pajak restoran PB1 (%), label di struk. Service dari subtotal − diskon; PB1 dari subtotal − diskon + service.
- **Jenis pesanan**: di tempat / bawa pulang / ojek online, dsb. (aktif, perlu nomor meja, kena service).
- **Pembayaran**: metode (laci tunai atau EDC/QRIS), wajib pilih bank, daftar bank.
- **Shift & kas**: nama & jam shift, pecahan uang yang dihitung, alasan kas masuk/keluar.
- **Aturan & keamanan**: batas persetujuan selisih kas, kas keluar, diskon; refund; nama pelanggan wajib; panjang PIN, salah PIN maksimal, lama dikunci, kunci otomatis.
- **Karyawan & peran**: karyawan (PIN kasir unik, email & kata sandi panel ≥ 12 karakter, aktif/nonaktif); peran dengan matriks hak akses (kasir, menyetujui, panel, laporan, menu, pelanggan, karyawan, pengaturan). Penyetuju = siapa pun yang punya hak "Menyetujui".
- **Pelanggan**: database (nama, no. HP unik, catatan alergi/preferensi, persetujuan dihubungi), riwayat kunjungan & belanja, program poin opsional, hapus data atas permintaan, unduh CSV. Di kasir: ketik nama/no. HP → saran pelanggan, atau simpan pelanggan baru dari kolom nama.
- **Perangkat**: tablet terdaftar, buat/batalkan kode pendaftaran.
- **Toko & struk**: nama, alamat, telepon, catatan kaki, karakter per baris, cetak tiket & struk otomatis.

Alur kasir (disepakati dengan pemilik):

1. **Pilih varian dulu, baru masuk**: ukuran, suhu, gula, tambahan, catatan untuk barista, dan jumlah — tombol menampilkan harga.
2. **Nama pelanggan wajib** (ditulis di cup) + **nomor antrean** harian otomatis (#1, #2, …).
3. **Bayar**:
   - **Tunai** — keypad + uang cepat; kembalian terlihat langsung, lalu **layar kembalian besar** sampai diketuk *Selesai*.
   - **QRIS / Debit via mesin EDC** — nominal besar untuk diketik di EDC, lalu *Pembayaran berhasil*. Debit **wajib memilih bank**.
4. **Cetak otomatis di satu printer**: **tiket barista** (antrean & nama besar, item + varian tebal) lalu **struk pelanggan** (bisa dimatikan per transaksi), kemudian langsung pesanan baru.
5. **Simpan (bayar nanti)**: tiket barista dicetak saat disimpan; item yang ditambah belakangan dicetak sebagai tiket **TAMBAHAN**; saat dibayar hanya struk yang dicetak.

Lainnya: diskon (% atau Rp), pajak opsional, cetak ulang dari notifikasi, pengaturan struk per perangkat, mode gelap.

### Shift (2 per hari: Pagi & Sore)

1. **Buka shift** (wajib, tidak bisa dilewati): pilih shift & kasir, hitung **modal awal per pecahan** (± atau ketik jumlah lembar, koin sebagai total), konfirmasi **PIN kasir**. Modal boleh berbeda tiap shift. Ada tombol **Tes printer**.
2. **Serah terima**: modal yang ditinggal shift sebelumnya tampil di layar buka shift; bila hitungan berbeda, catatan wajib.
3. **Selama shift** (tombol *Shift* di atas): ringkasan penjualan per metode, **kas keluar/masuk** (es batu, galon, …) dengan keterangan.
4. **Tutup shift**:
   - ditolak selama ada **pesanan tersimpan** yang belum dibayar;
   - **hitung buta**: kasir menghitung uang di laci dulu, jumlah seharusnya baru tampil sesudahnya;
   - seharusnya = modal awal + penjualan tunai + kas masuk − kas keluar; **selisih berapa pun wajib alasan**;
   - **modal ditinggal** untuk shift berikutnya, sisanya **diserahkan ke owner**;
   - **cetak laporan shift** 57 mm (penjualan per metode & bank, kas, selisih, setoran, tanda tangan kasir & owner).
**Open bill (simpan, bayar nanti) boleh tidak selesai hari itu:**
- Saat tutup shift, open bill **dialihkan** ke shift berikutnya (bukan menghalangi tutup shift), tercantum di laporan shift beserta totalnya; shift berikutnya diberi tahu saat buka shift.
- **Lewat hari** perlu persetujuan PIN owner/kepala toko (digabung dengan persetujuan selisih kas bila ada, satu PIN).
- **Kode pesanan tetap** (mis. B-1019-007) walau dibayar hari lain; tiket barista tidak dicetak ulang.
- **Penjualan dihitung saat dibayar** (masuk laporan & settlement hari bayar); waktu pesanan dibuat tetap tercatat.
- Open bill yang lewat batas umur (bawaan 3 hari) ditandai di kasir & admin; admin bisa **membatalkan dengan alasan** (tercatat siapa & kapan).
- Semua bisa diatur di admin › Pengaturan › Aturan (alihkan boleh/tidak, persetujuan lewat hari, batas umur, alasan pembatalan).

5. **Tutup hari** (otomatis di shift terakhir): cocokkan **settlement EDC** sekali sehari — total QRIS & Debit per bank semua shift dibandingkan dengan struk settlement; selisih wajib catatan.

Persetujuan dengan **PIN owner/kepala toko** (batas diatur di admin › Pengaturan › Aturan): kas keluar > Rp 100.000, selisih kas > Rp 20.000, diskon > 20%. Refund dilakukan di panel admin oleh yang berhak menyetujui.
Di mockup: PIN contoh Rani 1111, Dimas 2222, Sinta 3333, Owner 9999.
Struk **57 mm** (gulungan 57 × 38 mm, area cetak 48 mm = 32 karakter/baris).

## Layar sentuh (tablet Samsung)

Dirancang untuk Galaxy Tab dan diuji otomatis di 1280×800 / 800×1280 (Tab S9, A9+) dan 893×533 / 533×893 (Tab A9):

- semua tombol & input ≥ 44 px (tombol utama 56 px), jarak antar-tombol ≥ 8 px;
- keypad angka di layar untuk uang diterima & nomor meja (keyboard Android tidak menutupi layar);
- tanpa tarik-untuk-muat-ulang dan zoom ketuk-dua-kali; getar halus saat item ditambah;
- tombol **Bayar** selalu terlihat (layar pendek: menempel di bawah panel pesanan);
- ketuk baris pesanan untuk mengubah varian; konfirmasi sebelum mengosongkan pesanan;
- peringatan bila halaman ditutup saat pesanan belum selesai.

Uji: `cd tests/mockup && npm install && npm test` (sentuh di 4 ukuran Galaxy Tab termasuk layar masuk, alur kasir, buka/tutup shift, panel admin di 4 ukuran + konfigurasi terbaca di kasir + pelanggan). Versi Artifact: `node build-artifact.mjs`.

Saran di tablet: pakai **Chrome**, lalu *Tambahkan ke layar utama* supaya tampil penuh layar seperti aplikasi.

## Cetak struk

| Cara | Perangkat | Catatan |
|---|---|---|
| **Cetak Bluetooth** (Web Bluetooth → ESC/POS) | Chrome / Edge di Android, Windows, macOS | Printer harus mendukung **Bluetooth LE**. Tidak ada di Safari/iPad/iPhone. |
| **RawBT** | Android | Pasang aplikasi RawBT, pasangkan printer (Bluetooth klasik juga bisa). |
| **Dialog cetak** | Semua | Bila printer terpasang di sistem operasi. |

## Aplikasi (Laravel)

Dibangun dari `mehuli-id/starter-kit`: Laravel 13, Filament 5, Livewire 4, Tailwind v4, tema shadcn/ui (`mehuli/filament-shadcn`, package privat).

```bash
composer config --global github-oauth.github.com <TOKEN>   # sekali per komputer: akses baca mehuli-id/filament-shadcn
cp .env.example .env                                        # isi ADMIN_EMAIL, ADMIN_PASSWORD (opsional)
composer setup                                              # install, key, migrate, seed, npm build
composer dev                                                # atau: php artisan serve
composer test
```

Windows: lihat [`docs/pengembangan-windows.md`](docs/pengembangan-windows.md) (native dengan Herd/Scoop + SQLite, atau Docker lewat WSL2).

Panel admin (`/admin`): Menu, Kategori, Varian, Karyawan & Peran, dan Pengaturan (toko & struk, pajak & service, jenis pesanan, metode bayar, bank, shift, pecahan uang, alasan, aturan & keamanan, diskon cepat, pelanggan, perangkat). Akses per hak peran, dicek di server.

Kasir tablet: `http://localhost:8000/masuk` — daftar tablet dengan kode dari admin › Pengaturan › Perangkat (lokal: 246810), pilih kasir, PIN. Kunci otomatis & salah PIN berulang dikunci sementara (dicek di server).

Panel admin: `http://localhost:8000/admin` (email dari `ADMIN_EMAIL`; `ADMIN_PASSWORD` kosong → sandi acak ditampilkan sekali saat seeding).
Keamanan bawaan starter kit (header keamanan, sandi ≥ 12 karakter, `is_admin` tidak fillable, cookie Secure di production) dan daftar periksa production: lihat README starter kit. CI: `.github/workflows/tests.yml` (Ubuntu & Windows; secret `FILAMENT_SHADCN_TOKEN` opsional).
