# Rancangan skema database — Bangku Coffee POS

Sumber: `docs/mockup/shared/config.js` (konfigurasi) dan data yang disimpan mockup kasir/admin
(`sales`, `held`, `shifts`, `handover`, `voids`, `customers`, `devices`, `pairing`).
Status: **disetujui & dibuat** (migrasi `database/migrations/2026_10_24_*`, model `app/Models`, seeder, tes).

## Prinsip

- **Uang** = `unsignedBigInteger` rupiah bulat (tanpa desimal). **Persentase** = `decimal(5,2)`.
- **Tanpa hard-code**: semua daftar di `config.js` jadi tabel; nilai tunggal jadi tabel `settings`. Yang tetap di kode hanya hal yang dikenali sistem: daftar **hak akses** (enum `Permission`), **jenis alasan** (enum `ReasonType`), **jenis metode bayar** (`cash`/`edc`), dan status.
- **Data master tidak dihapus bila sudah dipakai**: `is_active` + FK `restrictOnDelete`. Transaksi menyimpan **salinan** (nama, harga, bank, metode) supaya laporan lama tidak berubah saat menu/harga diubah.
- **Transaksi tidak pernah dihapus**: batal & refund = status + baris baru, tercatat di `audit_logs`.
- **Tanggal bisnis** (`business_date`, cast `App\Casts\DateOnly` — selalu disimpan `Y-m-d`) = tanggal Asia/Jakarta saat shift dibuka; laporan & settlement memakai `business_date` pembayaran (penjualan dihitung saat dibayar).
- PIN & kata sandi **di-hash**; token perangkat & kode pendaftaran disimpan sebagai **hash** (nilai asli hanya ditampilkan sekali).
- Semua tabel memakai `id` bigint + `timestamps` kecuali disebut lain.

## 1. Pengaturan (nilai tunggal)

### `settings`
| Kolom | Tipe | Catatan |
|---|---|---|
| group | string | `store`, `charges`, `discounts`, `policy`, `customers`, `printing`, `orders` |
| key | string | unik bersama `group` |
| value | json | |

Kunci & bawaan (dari `config.js`), dibaca lewat `App\Support\Settings` (cache, bawaan di satu tempat, divalidasi di form Filament):

| group | key → bawaan |
|---|---|
| store | name "Bangku Coffee", address, phone, footer |
| charges | service_enabled false, service_rate 5, service_label "Service", tax_enabled false, tax_rate 10, tax_label "PB1" |
| discounts | approval_above_pct 20 |
| policy | variance_approval_above 20000, cash_out_approval_above 100000, cash_out_shift_approval_above 300000, refund_needs_approval true, carry_open_bills true, open_bill_day_approval true, open_bill_max_days 3, require_customer_name true, pin_length 4, max_pin_attempts 5, max_pin_attempts_device 15, lock_seconds 60, auto_lock_minutes 5 |
| customers | enabled true, ask_phone true, loyalty_enabled false, point_per 10000, point_value 100, redeem_min_points 50 |
| printing | cols 32, ticket true, receipt_default true |
| devices | pairing_code_hours 24 |
| orders | code_prefix "B" (kode pesanan `B-MMDD-NNN`) |

> Pilihan lain: satu tabel bertipe kolom (`store_settings`). Key-value dipilih karena grup pengaturan akan bertambah tanpa migrasi; setiap perubahan dicatat di `audit_logs`.

## 2. Peran, hak & karyawan

### `roles`
name (unik), description nullable, sort.
Contoh: Owner, Kepala toko, Kasir, Barista.

### `role_permissions`
role_id → roles (cascade), permission string (nilai enum `Permission`: `pos`, `approve`, `admin`, `reports`, `menu`, `customers`, `staff`, `settings`). Unik (role_id, permission).

### `users` (karyawan) — tabel bawaan starter kit, ditambah kolom
| Kolom | Tipe | Catatan |
|---|---|---|
| name | string | |
| email | string **nullable**, unik | hanya untuk yang masuk panel |
| password | string nullable | hash; ≥ 12 karakter (aturan starter kit) |
| role_id | FK roles nullable, restrict | tanpa peran = tanpa hak (kecuali `is_admin`) |
| pin | string nullable | **hash** PIN kasir; keunikan PIN dicek di aplikasi terhadap karyawan aktif (hash tidak bisa di-index unik) |
| pin_length | tinyint nullable | panjang PIN (diisi otomatis) — pengaturan "panjang PIN" ditolak selama ada kasir dengan PIN berbeda panjang |
| is_active | bool | nonaktif = tidak bisa masuk kasir/panel |
| is_admin | bool | bawaan starter kit, **tidak fillable**; super-admin (owner awal) |

Hak dicek di server: `User::hasPermission()`; setiap hak terdaftar sebagai Gate (`$user->can('approve')`). `canAccessPanel()` = `is_active && (is_admin || hasPermission('admin'))`.
Satu tabel untuk semua karyawan (bukan tabel `employees` terpisah) supaya audit, persetujuan, dan login panel memakai identitas yang sama.

## 3. Perangkat (tablet)

### `devices`
name, token_hash (unik, sha256 dari token acak), registered_at, registered_by (users, null on delete), last_seen_at nullable, revoked_at nullable, revoked_by nullable.
Token disimpan di cookie tablet; dicabut dari admin → semua permintaan dari tablet itu ditolak.

### `device_pairing_codes`
code_hash, device_name, created_by (users), expires_at, used_at nullable, device_id nullable (perangkat hasil pendaftaran). Sekali pakai.

> Rate limit salah PIN per perangkat & per karyawan memakai `RateLimiter` Laravel (cache store database), bukan kolom tabel; penguncian dicatat di `audit_logs`.

## 4. Menu

### `stations`
name (Bar, Dapur), sort. (Tiket per stasiun belum diputuskan; kolom disiapkan.)

### `categories`
name, station_id → stations (restrict), sort, is_active.

### `modifier_groups` (grup varian)
name, print_always bool, sort.

### `modifier_options`
modifier_group_id (cascade), name, price_delta (rupiah, ≥ 0), is_default bool (bawaan = pilihan pertama di mockup), sort, is_active.

### `menu_items`
category_id (restrict), name, price, icon (emoji) nullable, image_path nullable (upload tanpa SVG), is_active (tampil di kasir), is_sold_out, is_best_seller, sort, `softDeletes`.

### `menu_item_modifier_group`
menu_item_id, modifier_group_id, sort. Unik pasangan.

## 5. Pesanan, pembayaran & pengaturan kasir

### `order_types`
name ("Di tempat"), needs_table, applies_service, is_active, sort.

### `payment_methods`
name ("Tunai", "QRIS", "Debit", "Kartu kredit"), kind enum `cash|edc`, requires_bank, is_active, sort.

### `banks`
name, is_active, sort.

### `discount_presets`
type enum `percent|amount`, value, sort, is_active.

### `reasons`
type enum `cash_in|cash_out|refund|void|variance` (enum `ReasonType`), label, sort, is_active.
Satu tabel untuk semua daftar alasan; teks "Lainnya" = alasan biasa yang mewajibkan catatan (`requires_note` bool).

### `denominations`
value (rupiah), is_active, sort. Koin dihitung sebagai total (tidak per pecahan).

### `shift_templates`
name ("Pagi", "Sore"), starts_at time, ends_at time, sort, is_active.

## 6. Pelanggan

### `customers`
| Kolom | Tipe | Catatan |
|---|---|---|
| name | string | |
| phone | string nullable, **unik** | dinormalisasi `08…` (seperti `normPhone` mockup) |
| notes | text nullable | alergi/preferensi |
| contact_consent | bool | persetujuan dihubungi |
| points | int | saldo poin (bila program poin aktif) |
| visits_count, total_spent, last_visit_at | | ringkasan, diperbarui saat bayar/refund |

Hapus atas permintaan: baris pelanggan dihapus, `orders.customer_id` → null dan `orders.customer_name` dikosongkan; dicatat di `audit_logs` tanpa data pribadi.

## 7. Transaksi

### `order_sequences`
business_date (unik), last_number. Diambil dengan `lockForUpdate` → nomor antrean harian & kode pesanan.

### `orders` — satu baris untuk open bill maupun yang sudah dibayar
| Kolom | Tipe | Catatan |
|---|---|---|
| code | string unik | `B-1019-007`; **tetap** walau dibayar hari lain |
| business_date | date | hari pesanan dibuat |
| queue_number | int | antrean harian |
| status | enum | `open` (tersimpan) · `paid` · `voided` · `refunded` |
| customer_id | FK nullable, null on delete | |
| customer_name | string nullable | nama di cup |
| points_earned | int | poin yang didapat pelanggan dari pesanan ini (dikurangi lagi saat refund) |
| points_redeemed, points_discount | int | poin yang ditukar saat bayar & nilai potongannya (dikembalikan saat refund) |
| order_type_id | FK restrict | + order_type_name (salinan) |
| table_number | string nullable | |
| device_id | FK nullable | tablet pembuat |
| created_by | FK users | kasir pembuat |
| opened_shift_id | FK shifts | shift saat dibuat/disimpan |
| subtotal, discount_amount, service_amount, tax_amount, total | rupiah | |
| discount_type / discount_value | enum `percent|amount` nullable / int | aturan diskon yang dipilih |
| discount_approved_by | FK users nullable | bila di atas batas |
| service_rate, tax_rate | decimal | salinan saat dihitung |
| paid_at, paid_by, paid_shift_id, paid_business_date | nullable | penjualan dihitung di sini |
| receipt_printed | bool | struk dimatikan per transaksi |
| voided_at, voided_by, void_reason_id, void_note | nullable | batal open bill dengan alasan |
| day_carry_approved_by | FK users nullable | persetujuan open bill lewat hari |

Total dihitung **di server** (rumus: service dari subtotal − diskon; PB1 dari subtotal − diskon + service), bukan dikirim dari tablet.

### `order_items`
order_id (cascade), menu_item_id (null on delete), name, category_name, station_name (salinan), base_price, unit_price (harga + varian), quantity, note nullable, printed_quantity (sudah tercetak di tiket → sisanya dicetak sebagai **TAMBAHAN**), sort.

### `order_item_modifiers`
order_item_id (cascade), modifier_group_id / modifier_option_id (null on delete), group_name, option_name, price_delta, is_default, print_always (salinan; tiket/struk menulis pilihan yang bukan bawaan atau grup "selalu cetak").

### `payments`
order_id, payment_method_id (restrict) + method_name, method_kind (salinan), bank_id nullable + bank_name, amount, received (tunai), change, reference nullable (no. struk EDC, opsional), shift_id, business_date, user_id, paid_at.
Satu pembayaran per pesanan sekarang; tabel terpisah agar bayar terpisah (split) bisa ditambah nanti.

### `refunds`
order_id, amount, payment_method_id (+ salinan nama/jenis), reason_id + reason_label, note nullable, refunded_by, approved_by nullable, shift_id nullable (tunai dikembalikan dari laci shift berjalan), business_date, created_at.
Pesanan asli tetap ada; status → `refunded`.

## 8. Shift & kas

### `shifts`
| Kolom | Catatan |
|---|---|
| shift_template_id (+ name salinan), business_date, device_id | |
| opened_by, opened_at, opening_float | modal awal |
| previous_shift_id, handover_expected, handover_note | serah terima; catatan wajib bila beda |
| closed_by, closed_at | null = berjalan |
| expected_cash, counted_cash, variance, variance_reason_id, variance_note, variance_approved_by | hitung buta |
| float_left, deposit_amount | modal ditinggal, setoran ke owner |
| deposit_received_by, deposit_received_at | ditandai di admin |
| carried_open_bills_count, carried_open_bills_total, open_bills_approved_by | open bill dialihkan |
| is_day_close | shift terakhir hari itu |
| summary | json, ringkasan penjualan per metode & bank saat ditutup (laporan 57 mm tidak berubah) |

Unik: hanya satu shift berjalan per perangkat (dicek di aplikasi + transaksi DB).

### `shift_cash_counts`
shift_id, stage enum `opening|closing`, denomination (rupiah; `0` = koin total), quantity, amount.

### `shift_open_bills`
shift_id, order_id, total — salinan daftar open bill yang dialihkan saat tutup shift.

### `cash_movements`
shift_id, type enum `in|out`, amount, reason_id + reason_label, note nullable, user_id, approved_by nullable (kas keluar di atas batas), created_at.

### `edc_settlements` + `edc_settlement_lines`
Settlement: business_date (unik), shift_id (shift tutup hari), note, settled_by.
Baris: payment_method_id, bank_id nullable, label, pos_amount, edc_amount, difference. Selisih ≠ 0 wajib catatan.

## 9. Log audit (hanya-tambah)

### `audit_logs`
id, event (mis. `order.voided`, `order.refunded`, `shift.variance`, `cash.out`, `menu_item.price_changed`, `setting.updated`, `device.revoked`, `customer.erased`, `auth.pin_locked`), auditable_type/id nullable, user_id nullable, approved_by nullable, device_id nullable, old_values json, new_values json, ip_address, user_agent, created_at (tanpa updated_at).

Hanya-tambah dijaga dua lapis: model `AuditLog` melempar exception pada update/delete, dan **trigger database** (SQLite & MySQL) menolak `UPDATE`/`DELETE`.

## Relasi ringkas

```
roles 1─* role_permissions          roles 1─* users
categories *─1 stations             menu_items *─1 categories
menu_items *─* modifier_groups 1─* modifier_options
orders *─1 order_types, customers, users, devices, shifts
orders 1─* order_items 1─* order_item_modifiers
orders 1─* payments *─1 payment_methods, banks
orders 1─* refunds
shifts 1─* shift_cash_counts, cash_movements, shift_open_bills, payments
edc_settlements 1─* edc_settlement_lines
```

## Seeder (setelah disetujui)

- `ConfigSeeder`: semua isi `DEFAULT_CONFIG` (pengaturan, peran & hak, jenis pesanan, kategori + stasiun, grup varian, 18 menu, metode bayar, bank, preset diskon, shift, pecahan, alasan).
- `StaffSeeder`: Rani 1111, Dimas 2222 (kasir), Sinta 3333 (kepala toko, sinta@bangkucoffee.id), Owner 9999 (dari `ADMIN_*`, `is_admin`). Idempoten, tidak menimpa PIN/sandi yang sudah ada.
- `DeviceSeeder`: "Tablet Kasir 1" + kode pendaftaran 246810 untuk "Tablet Kasir 2" (hanya `local`).
- `DemoSeeder` (opsional, hanya `local`): 14 hari transaksi & shift contoh seperti tombol "Isi data contoh" di admin mockup, 12 pelanggan.
