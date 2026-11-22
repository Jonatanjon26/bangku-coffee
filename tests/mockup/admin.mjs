// Uji panel admin + konfigurasi bersama: masuk admin, semua halaman di 4 ukuran (tanpa overflow/error),
// ubah menu/pajak/pembayaran/jenis pesanan/karyawan → terbaca di kasir, database pelanggan, batas peran.
// Jalankan: node admin.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { BASE, login, PAIR_CODE } from './helpers.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const OUT = process.argv[2] || join(here, 'output');
mkdirSync(OUT, { recursive: true });
const fails = [];
const check = (ok, label) => { console.log(`${ok ? '✓' : '✗'} ${label}`); if (!ok) fails.push(label); };

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1440, height: 900 } });
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(String(e)));
const text = (s) => page.textContent(s);
const adminLogin = async (email, pw = 'password') => {
    await page.fill('#email', email); await page.fill('#password', pw); await page.click('#login-form [type=submit]'); await page.waitForTimeout(100);
};

await page.goto(BASE + 'admin.html');
await page.evaluate(() => localStorage.clear());
await page.reload();

// 1. Masuk admin
check(await page.isVisible('#login-form'), 'panel admin meminta masuk');
await adminLogin('owner@bangkucoffee.id', 'salah');
check(!(await page.isHidden('#login-err')) && (await text('#login-err')).includes('salah'), 'kata sandi salah ditolak');
await adminLogin('owner@bangkucoffee.id');
check(await page.isVisible('.sidebar'), 'owner masuk ke panel');
await page.click('[data-seed]'); await page.waitForTimeout(150);
check((await page.evaluate(() => JSON.parse(localStorage.getItem('bangku:sales')).length)) > 100 && (await text('.chart')) !== null, 'data contoh 14 hari terisi (transaksi & grafik)');

// 2. Semua halaman di 4 ukuran: tanpa overflow & error
const PAGES = ['dasbor', 'transaksi', 'shift', 'laporan', 'menu', 'varian', 'pelanggan', 'karyawan', 'pengaturan/toko', 'pengaturan/pajak', 'pengaturan/pesanan', 'pengaturan/bayar', 'pengaturan/shift', 'pengaturan/aturan', 'pengaturan/pelanggan', 'pengaturan/perangkat', 'pengaturan/data'];
for (const [w, h] of [[1440, 900], [1280, 800], [800, 1280], [390, 844]]) {
    await page.setViewportSize({ width: w, height: h });
    const bad = [];
    for (const p of PAGES) {
        await page.goto(BASE + 'admin.html#/' + p); await page.waitForTimeout(60);
        const ov = await page.evaluate(() => document.documentElement.scrollWidth - innerWidth);
        if (ov > 1) bad.push(`${p} overflow ${ov}px`);
        if (['dasbor', 'transaksi', 'menu', 'pengaturan/bayar'].includes(p)) await page.screenshot({ path: join(OUT, `admin-${w}x${h}-${p.replace('/', '-')}.png`), fullPage: false });
    }
    check(!bad.length, `${w}×${h}: ${PAGES.length} halaman tanpa overflow${bad.length ? ' — ' + bad.join(', ') : ''}`);
}
await page.setViewportSize({ width: 390, height: 844 });
await page.goto(BASE + 'admin.html#/dasbor');
await page.click('#menu-btn'); await page.waitForTimeout(250);
check(await page.evaluate(() => document.querySelector('.sidebar').getBoundingClientRect().left >= 0), 'ponsel: navigasi terbuka dari tombol menu');
await page.setViewportSize({ width: 1440, height: 900 });

// 3. Detail transaksi & refund, setoran shift
await page.goto(BASE + 'admin.html#/transaksi');
await page.click('tr[data-open="0"]'); await page.waitForTimeout(80);
check(await page.evaluate(() => document.querySelector('#sheet').open), 'detail transaksi terbuka');
await page.click('#refund'); await page.click('#confirm-ok'); await page.waitForTimeout(100);
check((await text('tbody')).includes('Refund'), 'transaksi bisa direfund dengan alasan');
await page.goto(BASE + 'admin.html#/shift');
await page.click('tr[data-open="0"]'); await page.click('#receive'); await page.waitForTimeout(80);
check((await text('tbody tr:first-child')).includes('setoran diterima'), 'setoran shift ditandai diterima owner');

// 4. Ubah konfigurasi
await page.goto(BASE + 'admin.html#/menu');
await page.click('#add');
await page.fill('[name="name"]', 'Es Teh Manis'); await page.selectOption('[name="categoryId"]', 'teh'); await page.fill('[name="price"]', '10000');
await page.check('[name="groups"][value="sugar"]');
await page.click('#item-form [type=submit]'); await page.waitForTimeout(80);
check((await text('tbody')).includes('Es Teh Manis'), 'menu baru ditambahkan');
await page.click('[data-toggle="soldOut"][data-id="2"]'); await page.waitForTimeout(80);

await page.goto(BASE + 'admin.html#/pengaturan/toko');
await page.fill('[data-path="store.name"]', '');
await page.click('#save');
check((await text('#errs')).includes('Nama toko wajib'), 'validasi: nama toko wajib');
await page.click('#reset');

await page.goto(BASE + 'admin.html#/pengaturan/pajak');
await page.click('[data-path="charges.serviceEnabled"]'); await page.click('[data-path="charges.taxEnabled"]'); await page.click('#save'); await page.waitForTimeout(80);
await page.goto(BASE + 'admin.html#/pengaturan/bayar');
await page.click('[data-add-row="payments"]');
await page.fill('[data-row="payments"][data-ri="4"][data-k="label"]', 'Transfer');
await page.click('#save'); await page.waitForTimeout(80);
await page.goto(BASE + 'admin.html#/pengaturan/pesanan');
await page.click('[data-row="orderTypes"][data-ri="2"][data-k="active"]'); await page.click('#save'); await page.waitForTimeout(80);
await page.goto(BASE + 'admin.html#/karyawan');
await page.click('#add');
await page.fill('[name="name"]', 'Bayu'); await page.fill('[name="pin"]', '1111');
await page.click('#staff-form [type=submit]'); await page.waitForTimeout(60);
check((await text('#staff-err')).includes('sudah dipakai'), 'validasi: PIN kasir tidak boleh sama');
await page.fill('[name="pin"]', '4444'); await page.click('#staff-form [type=submit]'); await page.waitForTimeout(80);
check((await text('tbody')).includes('Bayu'), 'kasir baru ditambahkan');

// 5. Kasir membaca konfigurasi baru
await ctx.clearCookies();
const tablet = await browser.newContext({ viewport: { width: 1280, height: 800 }, hasTouch: true, isMobile: true });
const k = await tablet.newPage();
k.on('pageerror', (e) => errors.push('kasir: ' + e));
// konteks baru = penyimpanan baru: salin konfigurasi & data dari admin (satu browser di tablet nyata)
const dump = await page.evaluate(() => JSON.stringify(Object.fromEntries(Object.keys(localStorage).map((x) => [x, localStorage.getItem(x)]))));
await k.goto(BASE + 'masuk.html');
await k.evaluate((d) => { localStorage.clear(); for (const [x, v] of Object.entries(JSON.parse(d))) if (!/session|device|adminSession/.test(x)) localStorage.setItem(x, v); }, dump);
await k.reload();
for (const d of PAIR_CODE) await k.tap(`#pair-keypad [data-k="${d}"]`);
await k.waitForTimeout(80);
check((await k.textContent('#people')).includes('Bayu'), 'kasir baru tampil di layar masuk tablet');
await login(k, 'Rani');
await k.tap('#open-ok'); await k.waitForTimeout(80);
check((await k.textContent('#grid')).includes('Es Teh Manis'), 'menu baru tampil di kasir');
check(await k.isDisabled('[data-id="2"]'), 'menu yang ditandai habis tidak bisa dipilih');
check((await k.textContent('#methods')).includes('Transfer') && (await k.textContent('#types')).includes('Ojek online'), 'metode & jenis pesanan baru tampil di kasir');
await k.tap('[data-id="13"]'); // croissant 20.000, di tempat → service 5% & PB1 10%
check((await k.textContent('#service')) === 'Rp 1.000' && (await k.textContent('#tax')) === 'Rp 2.100' && (await k.textContent('#total')) === 'Rp 23.100', 'service 5% lalu PB1 10% dihitung benar (20.000 → 23.100)');
await k.tap('[data-type="take"]');
check((await k.textContent('#total')) === 'Rp 22.000', 'bawa pulang tanpa service (20.000 + PB1 = 22.000)');

// 6. Database pelanggan dari kasir
await k.fill('#customer', 'Rara');
await k.tap('#cust-btn');
await k.fill('#cust-phone', '0813 5555 1234');
await k.tap('#cust-consent');
await k.tap('#cust-save'); await k.waitForTimeout(80);
check(await k.isVisible('#cust-tag') && (await k.textContent('#cust-tag')).includes('0813'), 'pelanggan baru disimpan & terhubung ke pesanan');
await k.tap('[data-method="cash"]'); await k.tap('#checkout'); await k.tap('#cash-quick [data-paid]'); await k.tap('#pay-ok'); await k.waitForTimeout(120);
await k.tap('#change-done'); await k.waitForTimeout(120);
if (await k.evaluate(() => document.querySelector('#receipt-dlg').open)) await k.tap('#receipt-close');
const receipt = await k.evaluate(() => JSON.parse(localStorage.getItem('bangku:last')));
check(receipt.total === 22000 && receipt.customerId, 'transaksi tercatat dengan pelanggan');
await k.tap('[data-id="13"]');
await k.fill('#customer', '08135');
await k.dispatchEvent('#customer', 'input');
check(await k.isVisible('#suggest') && (await k.textContent('#suggest')).includes('Rara'), 'cari pelanggan dengan no. HP memunculkan saran');
await k.tap('#suggest [data-cust]');
check((await k.inputValue('#customer')) === 'Rara' && (await k.textContent('#cust-tag')).includes('1× kunjungan'), 'pilih saran: nama & riwayat kunjungan terisi');

// 6b. Open bill lama di admin: tampil dengan umur, ditandai lewat batas, bisa dibatalkan dengan alasan.
await page.evaluate(() => localStorage.setItem('bangku:held', JSON.stringify([{ uid: 'h1', queue: 7, code: 'B-1019-007', name: 'Pak Andi', type: 'dine', table: '4', customerId: null, total: 45000, createdAt: new Date(Date.now() - 5 * 86400000).toISOString(), carriedFrom: 'Sore 2026-10-19', lines: [{ id: 1, qty: 2, picks: {}, note: '', printed: 2 }], discount: { kind: 'none', value: 0 } }])));
await page.goto(BASE + 'admin.html#/dasbor'); await page.reload();
check((await text('.sidebar')).includes('Transaksi1') || (await page.textContent('a[href="#/transaksi"] .count')) === '1', 'navigasi: tanda open bill lewat batas');
check((await text('.page')).includes('1 open bill belum dibayar'), 'dasbor: ringkasan open bill');
await page.goto(BASE + 'admin.html#/transaksi');
await page.click('#t-method [data-v="open"]'); await page.waitForTimeout(80);
check((await text('tbody')).includes('B-1019-007') && (await text('tbody')).includes('5 hari'), 'tab Open bill: kode asli & umur 5 hari');
await page.click('tr[data-open="0"]'); await page.waitForTimeout(80);
check((await text('#sheet')).includes('sudah dibuat') && (await text('#sheet')).includes('dialihkan dari shift'), 'detail open bill: status tiket & asal shift');
await page.selectOption('#void-reason', { index: 1 });
await page.click('#void'); await page.click('#confirm-ok'); await page.waitForTimeout(100);
const voided = await page.evaluate(() => ({ held: JSON.parse(localStorage.getItem('bangku:held')).length, voids: JSON.parse(localStorage.getItem('bangku:voids')) }));
check(voided.held === 0 && voided.voids.length === 1 && voided.voids[0].voidReason && voided.voids[0].voidedBy === 'Owner', 'open bill dibatalkan: alasan & pembatal tercatat');

// 7. Peran: kepala toko tidak melihat Pengaturan & Karyawan
await page.goto(BASE + 'admin.html#/dasbor');
await page.click('#logout'); await page.waitForTimeout(60);
await adminLogin('sinta@bangkucoffee.id');
const nav = await text('.sidebar');
check(nav.includes('Menu') && !nav.includes('Pengaturan') && !nav.includes('Karyawan'), 'kepala toko: tanpa Pengaturan & Karyawan');
await page.goto(BASE + 'admin.html#/pengaturan');
check((await text('.topbar h2')) !== 'Pengaturan', 'kepala toko tidak bisa membuka Pengaturan lewat alamat');

check(!errors.length, 'tanpa error JavaScript' + (errors.length ? ': ' + errors.join('; ') : ''));
await browser.close();
console.log(fails.length ? `\n${fails.length} gagal` : '\nsemua lolos');
process.exit(fails.length ? 1 : 0);
