// Uji alur shift: buka (kasir, PIN, modal per pecahan), kas masuk/keluar (PIN owner di atas batas),
// tutup shift (pesanan tersimpan menghalangi, hitung buta, selisih wajib alasan / PIN owner, setoran ke owner),
// serah terima modal ke shift Sore, tutup hari dengan pencocokan settlement EDC. Jalankan: node shift.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { freshLogin, login, typePin } from './helpers.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const OUT = process.argv[2] || join(here, 'output');
mkdirSync(OUT, { recursive: true });

const fails = [];
const check = (ok, label) => { console.log(`${ok ? '✓' : '✗'} ${label}`); if (!ok) fails.push(label); };

const browser = await chromium.launch();
const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, hasTouch: true, isMobile: true, userAgent: 'Mozilla/5.0 (Linux; Android 14; SM-X210) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36' });
const page = await ctx.newPage();
const errors = [];
page.on('pageerror', (e) => errors.push(String(e)));
await freshLogin(page, 'Rani');

const isOpen = (id) => page.evaluate((id) => document.querySelector(id).open, id);
const text = (sel) => page.textContent(sel);
const closePreview = async () => { if (await isOpen('#receipt-dlg')) await page.tap('#receipt-close'); await page.waitForTimeout(80); };
const keypad = async (digits) => { for (const d of digits) await page.tap(`#keypad-dlg [data-k="${d}"]`); await page.tap('#keypad-dlg [data-k="ok"]'); await page.waitForTimeout(80); };
const sell = async (id, name, method, { bank } = {}) => {
    await page.tap(`[data-id="${id}"]`);
    if (await isOpen('#opt-dlg')) await page.tap('#opt-save');
    await page.fill('#customer', name);
    await page.tap(`[data-method="${method}"]`);
    await page.tap('#checkout');
    if (method === 'cash') await page.tap('#cash-quick [data-paid]');
    if (bank) await page.tap(`[data-bank="${bank}"]`);
    await page.tap('#pay-ok'); await page.waitForTimeout(120);
    if (method === 'cash') { await page.tap('#change-done'); await page.waitForTimeout(120); }
    await closePreview();
};

// 1. Buka shift wajib dan tidak bisa dilewati.
check(await isOpen('#open-dlg'), 'saat mulai, layar Buka shift tampil');
await page.keyboard.press('Escape');
check(await isOpen('#open-dlg'), 'Escape tidak menutup Buka shift');
check((await text('#open-sub')).includes('Kasir Rani'), 'kasir yang masuk (Rani) tercatat di Buka shift');
await page.screenshot({ path: join(OUT, 'shift-01-buka.png') });

// 2. Modal dihitung per pecahan (+, ketik jumlah, koin).
await page.tap('#open-shift [data-shift="Pagi"]');
await page.tap('#open-denoms [data-d="100000"][data-step="1"]');
await page.tap('#open-denoms [data-d="100000"][data-step="1"]');
await page.tap('#open-denoms .n[data-d="20000"]'); await keypad('5');
await page.tap('#open-denoms [data-coins]'); await keypad('3000');
check((await text('#open-total')) === 'Rp 303.000', `modal = 2×100rb + 5×20rb + koin 3rb = Rp 303.000 (${await text('#open-total')})`);
await page.tap('#open-ok'); await page.waitForTimeout(80);
check(!(await isOpen('#open-dlg')), 'shift dibuka');
check((await text('#shift-label')) === 'Shift Pagi' && (await text('#cashier')) === 'Rani', 'topbar menampilkan Shift Pagi · Rani');

// 3. Penjualan tercatat ke shift.
await sell(1, 'Ana', 'cash');   // Kopi Susu 22.000
await sell(3, 'Budi', 'qris');   // Americano 20.000
await sell(8, 'Cici', 'debit', { bank: 'BCA' }); // Matcha 27.000
check((await page.evaluate(() => JSON.parse(localStorage.getItem('bangku:shift')).sales.length)) === 3, '3 penjualan tercatat di shift');
check((await text('#receipt')) !== null, 'struk tetap jalan');

// 4. Kas keluar: di atas Rp 100.000 perlu PIN owner; di bawahnya tidak.
await page.tap('#shift-chip');
check((await text('#shift-stats')).includes('Rp 69.000'), 'ringkasan shift: penjualan Rp 69.000');
await page.tap('#shift-dlg [data-s="out"]');
for (const k of ['1', '5', '000', '0']) await page.tap(`#kas-keypad [data-k="${k}"]`);
check((await text('#kas-display')) === 'Rp 150.000', 'kas keluar Rp 150.000 diketik dengan keypad');
check(await page.isDisabled('#kas-ok'), 'kas keluar butuh keterangan');
await page.tap('#kas-reasons [data-reason="Es batu"]');
check((await text('#kas-hint')).includes('PIN owner'), 'peringatan perlu PIN owner tampil');
await page.tap('#kas-ok');
check(await isOpen('#pin-dlg'), 'PIN owner diminta untuk kas keluar besar');
await typePin(page, '9999');
await page.tap('#shift-chip'); await page.tap('#shift-dlg [data-s="out"]');
for (const k of ['2', '0', '000']) await page.tap(`#kas-keypad [data-k="${k}"]`);
await page.tap('#kas-reasons [data-reason="Galon"]');
await page.tap('#kas-ok'); await page.waitForTimeout(80);
check(!(await isOpen('#pin-dlg')) && !(await isOpen('#kas-dlg')), 'kas keluar Rp 20.000 tanpa PIN owner');

// 5. Open bill: tutup shift menawarkan pengalihan (dibatalkan dulu di sini, lalu dibayar).
await page.tap('[data-id="13"]'); await page.fill('#customer', 'Dodi');
await page.tap('#hold'); await page.waitForTimeout(120); await closePreview();
await page.tap('#shift-chip'); await page.tap('#shift-close');
check(await isOpen('#confirm-dlg') && (await text('#confirm-title')).includes('pesanan terbuka') && (await text('#confirm-ok')).includes('Alihkan') && (await text('#confirm-text')).includes('#4 Dodi'), 'tutup shift dengan open bill: tawaran alihkan + daftar pesanan');
await page.tap('#confirm-dlg button[value="cancel"]'); await page.waitForTimeout(80);
check(!(await isOpen('#close-dlg')), 'batal: shift belum ditutup');
await page.tap('#held-btn'); await page.tap('[data-uid]');
await page.tap('[data-method="qris"]'); await page.tap('#checkout'); await page.tap('#pay-ok'); await page.waitForTimeout(120); await closePreview();

// 6. Tutup shift: hitung buta, selisih kecil wajib alasan, setoran ke owner.
// Seharusnya = 303.000 + 22.000 tunai − 170.000 kas keluar = 155.000. Dihitung 150.000 → kurang 5.000.
await page.tap('#shift-chip'); await page.tap('#shift-close');
check(await isOpen('#close-dlg'), 'tutup shift terbuka');
check(!(await text('#close-body')).includes('Seharusnya'), 'hitung buta: jumlah seharusnya belum ditampilkan');
await page.tap('#close-denoms [data-d="100000"][data-step="1"]');
await page.tap('#close-denoms [data-d="50000"][data-step="1"]');
await page.screenshot({ path: join(OUT, 'shift-02-hitung.png') });
await page.tap('#close-next');
check((await text('#close-variance')).includes('Kas kurang') && (await text('#close-variance')).includes('Rp 5.000'), 'hasil: kas kurang Rp 5.000');
check((await text('#close-body')).includes('Rp 155.000'), 'seharusnya di laci Rp 155.000');
check(await page.isDisabled('#close-next'), 'selisih wajib alasan');
await page.fill('#close-reason', 'salah beri kembalian');
check(!(await page.isDisabled('#close-next')), 'setelah alasan diisi, bisa lanjut');
check((await page.getAttribute('#close-day', 'aria-checked')) === 'false', 'shift Pagi: tutup hari tidak aktif');
await page.tap('#close-float'); await keypad('100000');
check((await text('#close-deposit')) === 'Rp 50.000', 'modal ditinggal 100rb → diserahkan ke owner Rp 50.000');
await page.screenshot({ path: join(OUT, 'shift-03-hasil.png') });
await page.tap('#close-next'); await page.waitForTimeout(150);
check(!(await isOpen('#pin-dlg')), 'selisih ≤ Rp 20.000 tanpa PIN owner');
const r1 = await text('#receipt');
check(r1.includes('LAPORAN SHIFT') && r1.includes('DISERAHKAN KE OWNER') && r1.includes('50.000') && r1.includes('salah beri kembalian'), 'laporan shift tercetak (setoran & alasan selisih)');
check(r1.replace(/\s+/g, ' ').includes('Keluar: Es batu -150.000 disetujui Owner'), 'laporan mencatat kas keluar yang disetujui owner');
await page.screenshot({ path: join(OUT, 'shift-04-laporan.png') });
await closePreview();

// 7. Serah terima ke shift Sore.
check(await isOpen('#open-dlg'), 'setelah tutup, layar Buka shift tampil lagi');
check((await text('#open-handover')).includes('Rp 100.000'), 'serah terima modal Rp 100.000 tampil');
await page.tap('#open-logout');
await page.waitForURL(/masuk\.html/);
check(await page.isVisible('#locked-notice'), 'Ganti kasir: kembali ke layar masuk');
await login(page, 'Dimas');
check((await text('#open-handover')).includes('Rp 100.000') && (await text('#open-sub')).includes('Kasir Dimas'), 'Dimas masuk, serah terima tetap tampil');
await page.tap('#open-shift [data-shift="Sore"]');
await page.tap('#open-denoms [data-d="50000"][data-step="1"]');
check(await page.isDisabled('#open-ok') && !(await page.isHidden('#open-note-wrap')), 'modal beda dari serah terima: catatan wajib');
await page.tap('#open-denoms [data-d="50000"][data-step="1"]');
check(!(await page.isDisabled('#open-ok')), 'modal sama dengan serah terima: bisa buka');
await page.tap('#open-ok'); await page.waitForTimeout(80);
check((await text('#shift-label')) === 'Shift Sore', 'Shift Sore dibuka oleh Dimas');

// 8. Tutup hari: open bill dialihkan lewat hari, selisih besar → satu PIN persetujuan, cocokkan settlement EDC.
await sell(4, 'Eko', 'debit', { bank: 'Mandiri' }); // Cappuccino 25.000
await page.tap('[data-id="16"]'); await page.fill('#customer', 'Fani');
await page.tap('#hold'); await page.waitForTimeout(120); await closePreview();
const fani = await page.evaluate(() => JSON.parse(localStorage.getItem('bangku:held'))[0]);
await page.tap('#shift-chip'); await page.tap('#shift-close');
await page.tap('#confirm-ok'); await page.waitForTimeout(80);
check(await isOpen('#close-dlg'), 'open bill dialihkan: tutup shift berlanjut');
await page.tap('#close-denoms [data-d="50000"][data-step="1"]');
await page.tap('#close-denoms [data-d="20000"][data-step="1"]');
await page.tap('#close-next');
check((await text('#close-variance')).includes('Rp 30.000') && (await text('#close-body')).includes('perlu PIN owner'), 'kurang Rp 30.000: perlu PIN owner');
await page.fill('#close-reason', 'uang dipinjam, diganti besok');
check((await page.getAttribute('#close-day', 'aria-checked')) === 'true', 'shift Sore: tutup hari aktif otomatis');
check((await text('#close-openbills')).includes('1 open bill') && (await text('#close-openbills')).includes('Rp 20.000'), 'hasil tutup shift menyebut open bill yang dialihkan');
await page.tap('#close-next');
const edc = await text('#close-body');
check(edc.includes('QRIS') && edc.includes('Debit BCA') && edc.includes('Debit Mandiri') && edc.includes('Rp 40.000'), 'EDC: total QRIS (Rp 40.000) & debit per bank seluruh hari');
check(await page.isDisabled('#close-next'), 'semua angka EDC wajib diisi');
await page.tap('[data-edc="QRIS"]'); await keypad('40000');
await page.tap('[data-edc="Debit BCA"]'); await keypad('27000');
await page.tap('[data-edc="Debit Mandiri"]'); await keypad('20000');
check((await text('#close-body')).includes('Selisih −Rp 5.000') && await page.isDisabled('#close-next'), 'EDC beda: selisih tampil & catatan wajib');
await page.fill('#close-edc-note', 'cek ulang ke bank');
await page.screenshot({ path: join(OUT, 'shift-05-edc.png') });
await page.tap('#close-next'); await page.waitForTimeout(80);
check(await isOpen('#pin-dlg') && (await text('#pin-sub')).includes('Selisih kas') && (await text('#pin-sub')).includes('open bill lewat hari'), 'satu PIN persetujuan untuk selisih kas & open bill lewat hari');
await typePin(page, '9999'); await page.waitForTimeout(150);
const r2 = await text('#receipt');
check(r2.includes('TUTUP HARI') && r2.includes('SETTLEMENT EDC') && r2.includes('cek ulang ke bank'), 'laporan tutup hari memuat settlement EDC');
check(r2.includes('OPEN BILL DIALIHKAN') && r2.includes('Fani') && r2.includes('disetujui Owner'), 'laporan memuat open bill dialihkan & penyetujunya');
await closePreview();
check((await text('#open-handover')).includes('1 open bill'), 'buka shift berikutnya: open bill yang terbawa diberitahukan');

// 8b. Open bill dari beberapa hari lalu: kode tetap, umur ditandai, dibayar → masuk penjualan hari bayar.
await page.evaluate(() => { const h = JSON.parse(localStorage.getItem('bangku:held')); h[0].createdAt = new Date(Date.now() - 4 * 86400000).toISOString(); localStorage.setItem('bangku:held', JSON.stringify(h)); });
await page.reload(); await page.waitForSelector('#open-dlg[open]');
await page.fill('#open-note', 'uji'); await page.tap('#open-ok'); await page.waitForTimeout(80);
await page.tap('#held-btn');
check((await text('#held-list')).includes('4 hari') && (await text('#held-list')).includes(fani.code), 'open bill 4 hari: ditandai "tagih / batalkan", kode asli tetap');
await page.tap('[data-uid]');
check((await text('#order-no')).startsWith(fani.code), 'pesanan lama dibuka dengan kode aslinya');
await page.tap('[data-method="qris"]'); await page.tap('#checkout'); await page.tap('#pay-ok'); await page.waitForTimeout(120);
const rcp = await text('#receipt');
check(rcp.includes(fani.code) && !rcp.includes('TIKET BARISTA'), 'dibayar: struk memakai kode asli, tiket tidak dicetak ulang');
await closePreview();
const paidFani = await page.evaluate((code) => JSON.parse(localStorage.getItem('bangku:sales')).find((x) => x.code === code), fani.code);
check(!!paidFani && paidFani.openedAt && JSON.parse(await page.evaluate(() => localStorage.getItem('bangku:held'))).length === 0, 'tercatat sebagai penjualan saat dibayar (dengan waktu pesanan dibuat)');

// 9. Lebar cetakan laporan.
const widths = await page.evaluate(() => {
    const shifts = JSON.parse(localStorage.getItem('bangku:shifts'));
    const all = shifts.flatMap((x) => window.__bangku.shiftReportLines(x).lines);
    return { normal: Math.max(...all.filter((l) => l.style !== 'big' && l.style !== 'big-c').map((l) => l.text.length)), count: shifts.length };
});
check(widths.count === 2, '2 shift ditutup tersimpan di riwayat');
check(widths.normal <= 32, `laporan: baris ≤ 32 karakter (${widths.normal})`);
check(!errors.length, 'tanpa error JavaScript' + (errors.length ? ': ' + errors.join('; ') : ''));

await browser.close();
console.log(fails.length ? `\n${fails.length} gagal` : '\nsemua lolos');
process.exit(fails.length ? 1 : 0);
