// Uji alur kasir "mode cepat": nama wajib + antrean, varian dipilih dulu, bayar Tunai/QRIS/Debit (bank wajib),
// layar kembalian, tiket barista + struk, simpan (bayar nanti) dengan tiket TAMBAHAN. Jalankan: node flow.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { freshLogin, openShift } from './helpers.mjs';

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
await freshLogin(page);
await openShift(page);

const previewText = () => page.textContent('#receipt');
const closePreview = async () => { if (await page.evaluate(() => document.querySelector('#receipt-dlg').open)) await page.tap('#receipt-close'); };
const addDrink = async (id, { size, qty = 1 } = {}) => {
    await page.tap(`[data-id="${id}"]`);
    if (size !== undefined) await page.tap(`[data-group="size"] [data-i="${size}"]`);
    for (let i = 1; i < qty; i++) await page.tap('[data-q="1"]');
    await page.tap('#opt-save');
    await page.waitForTimeout(80);
};

// 1. Varian dipilih dulu (dialog), jumlah di dialog, harga di tombol.
await page.tap('[data-id="1"]');
check(await page.evaluate(() => document.querySelector('#opt-dlg').open), 'ketuk minuman membuka pilihan varian dulu');
await page.tap('[data-group="size"] [data-i="1"]');
await page.tap('[data-q="1"]');
check((await page.textContent('#opt-save')).includes('Rp 54.000'), 'tombol Tambah menampilkan harga × jumlah (2 × Large 27rb = 54rb)');
await page.screenshot({ path: join(OUT, 'flow-01-varian.png') });
await page.tap('#opt-save');
await page.tap('[data-id="13"]'); // makanan tanpa varian: langsung masuk
check((await page.evaluate(() => window.__bangku.order.lines.length)) === 2, 'makanan tanpa varian langsung masuk');

// 2. Nama wajib.
await page.tap('#checkout');
check(!(await page.evaluate(() => document.querySelector('#pay-dlg').open)), 'bayar tanpa nama ditolak');
check(await page.evaluate(() => document.querySelector('#name-field').classList.contains('invalid')), 'kolom nama ditandai wajib');
check((await page.textContent('#queue-no')) === '#1', 'nomor antrean #1 tampil');

// 3. Tunai: uang cepat, kembalian besar.
await page.fill('#customer', 'Rani');
await page.tap('#checkout');
check(await page.evaluate(() => document.querySelector('#pay-dlg').open), 'dialog bayar tunai terbuka');
check(await page.isDisabled('#pay-ok'), 'tombol terima nonaktif sebelum uang cukup');
await page.tap('#cash-keypad [data-k="5"]'); await page.tap('#cash-keypad [data-k="0"]'); await page.tap('#cash-keypad [data-k="000"]');
check((await page.textContent('#change-live')).includes('Kurang'), 'uang 50rb untuk 74rb: tampil "Kurang"');
await page.tap('#cash-quick [data-paid="100000"]');
const live = await page.textContent('#change-live');
check(live.includes('Kembalian') && live.includes('Rp 26.000'), 'kembalian langsung terlihat di dialog (Rp 26.000)');
await page.screenshot({ path: join(OUT, 'flow-02-tunai.png') });
await page.tap('#pay-ok');
await page.waitForTimeout(150);
check(await page.evaluate(() => document.querySelector('#change-dlg').open), 'layar kembalian penuh muncul');
check((await page.textContent('#change-amount')) === 'Rp 26.000', 'angka kembalian besar = Rp 26.000');
check(!(await page.evaluate(() => document.querySelector('#receipt-dlg').open)), 'pratinjau cetak tidak menutupi layar kembalian');
await page.screenshot({ path: join(OUT, 'flow-03-kembalian.png') });
await page.tap('#change-done');
await page.waitForTimeout(150);
const t1 = await previewText();
check(t1.includes('TIKET BARISTA') && t1.includes('RANI') && t1.includes('2x Kopi Susu Bangku'), 'tiket barista: nama besar & item');
check(t1.includes('Kembali') && t1.includes('26.000') && t1.includes('Antrean #1'), 'struk pelanggan: antrean & kembalian');
await page.screenshot({ path: join(OUT, 'flow-04-cetak.png') });
await closePreview();
check((await page.textContent('#queue-no')) === '#2', 'pesanan baru otomatis, antrean jadi #2');

// 4. Debit: bank wajib.
await addDrink(3);
await page.fill('#customer', 'Budi');
await page.tap('[data-method="debit"]');
await page.tap('#checkout');
check(await page.isDisabled('#pay-ok'), 'Debit: tombol berhasil nonaktif sebelum bank dipilih');
check((await page.textContent('#pay-body')).includes('Rp 20.000'), 'Debit: nominal untuk EDC tampil');
await page.tap('[data-bank="BCA"]');
check(!(await page.isDisabled('#pay-ok')), 'Debit: aktif setelah memilih bank');
await page.screenshot({ path: join(OUT, 'flow-05-debit.png') });
await page.tap('#pay-ok'); await page.waitForTimeout(150);
check((await previewText()).includes('Debit BCA'), 'struk mencatat "Debit BCA"');
await closePreview();

// 5. QRIS: langsung bisa.
await addDrink(8);
await page.fill('#customer', 'Sari');
await page.tap('[data-method="qris"]');
await page.tap('#checkout');
check(!(await page.isDisabled('#pay-ok')), 'QRIS: cukup metode & nominal');
await page.tap('#receipt-switch');
await page.tap('#pay-ok'); await page.waitForTimeout(150);
const t3 = await previewText();
check(t3.includes('TIKET BARISTA') && !t3.includes('TOTAL'), 'tanpa struk pelanggan bila sakelar dimatikan (tiket tetap)');
await closePreview();

// 6. Simpan (bayar nanti) → tambah item → tiket TAMBAHAN → bayar.
await addDrink(2);
await page.fill('#customer', 'Meja Lima');
await page.tap('#table'); await page.tap('#keypad-dlg [data-k="5"]'); await page.tap('#keypad-dlg [data-k="ok"]');
await page.tap('#hold'); await page.waitForTimeout(150);
const t4 = await previewText();
check(t4.includes('TIKET BARISTA') && t4.includes('1x Es Kopi Aren') && !t4.includes('TOTAL'), 'simpan: tiket barista dicetak, tanpa struk');
await closePreview();
check((await page.textContent('#held-count')) === '1', 'daftar Tersimpan berisi 1');
await page.tap('#held-btn'); await page.tap('[data-uid]');
await page.tap('[data-id="16"]');
await page.tap('#hold'); await page.waitForTimeout(150);
const t5 = await previewText();
check(t5.includes('TAMBAHAN') && t5.includes('1x Kentang Goreng') && !t5.includes('Es Kopi Aren'), 'tambah item: tiket TAMBAHAN hanya berisi item baru');
await closePreview();
await page.tap('#held-btn'); await page.tap('[data-uid]');
await page.tap('[data-method="cash"]');
await page.tap('#checkout'); await page.tap('#cash-quick [data-paid]'); await page.tap('#pay-ok'); await page.waitForTimeout(150);
await page.tap('#change-done'); await page.waitForTimeout(150);
const t6 = await previewText();
check(!t6.includes('TIKET BARISTA') && t6.includes('TOTAL') && t6.includes('Meja 5'), 'bayar pesanan tersimpan: hanya struk (tiket sudah dicetak)');
await closePreview();
check((await page.textContent('#held-count')) === '0' && await page.isHidden('#held-btn'), 'pesanan tersimpan hilang dari daftar setelah dibayar');

// 7. Lebar cetakan.
const widths = await page.evaluate(() => {
    const o = JSON.parse(localStorage.getItem('bangku:last'));
    const t = window.__bangku.ticketLines(o), r = window.__bangku.receiptLines(o);
    return { normal: Math.max(...[...t.lines, ...r.lines].filter((l) => l.style !== 'big').map((l) => l.text.length)), big: Math.max(...t.lines.filter((l) => l.style === 'big').map((l) => l.text.length)), bytes: Array.from(window.__bangku.escpos({ ticket: t, receipt: r })).filter((b) => b > 0x7f).length };
});
check(widths.normal <= 32, `baris biasa ≤ 32 karakter (${widths.normal})`);
check(widths.big <= 16, `baris besar (2× lebar) ≤ 16 karakter (${widths.big})`);
check(widths.bytes === 0, 'ESC/POS hanya ASCII');
check(!errors.length, 'tanpa error JavaScript' + (errors.length ? ': ' + errors.join('; ') : ''));

await browser.close();
console.log(fails.length ? `\n${fails.length} gagal` : '\nsemua lolos');
process.exit(fails.length ? 1 : 0);
