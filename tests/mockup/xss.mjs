// Uji keamanan XSS: semua teks yang bisa diisi (pengaturan, menu, karyawan, pelanggan, transaksi, shift)
// diisi kode HTML berbahaya; tidak boleh ada halaman kasir/masuk/admin yang menjalankannya. Jalankan: node xss.mjs
import { chromium } from 'playwright';
import { BASE } from './helpers.mjs';
const P = '<img src=x onerror="window.__xss=(window.__xss||0)+1">';
const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 1280, height: 800 } }); const p = await ctx.newPage(); p.setDefaultTimeout(3000);
const errors = []; p.on('pageerror', (e) => errors.push(String(e)));
await p.goto(BASE + 'admin.html');
await p.evaluate((P) => {
  localStorage.clear();
  const cfg = window.Bangku.config();
  cfg.store.name = 'Toko' + P; cfg.store.address = P; cfg.store.footer = P;
  cfg.items[0].name = 'Kopi' + P; cfg.items[0].emoji = P;
  cfg.categories[0].name = 'Kat' + P; cfg.categories[0].station = P;
  cfg.modifierGroups[0].name = 'Grp' + P; cfg.modifierGroups[0].options[1].name = 'Opt' + P;
  cfg.staff[0].name = 'Rani' + P; cfg.roles[2].name = 'Kasir' + P;
  cfg.payments[1].label = 'QRIS' + P; cfg.banks[0] = 'BCA' + P; cfg.orderTypes[0].label = 'Dine' + P;
  cfg.shifts[0].name = 'Pagi' + P; cfg.cashReasons.out[0] = 'Es' + P; cfg.refundReasons[0] = 'R' + P;
  cfg.discounts.presets[0] = P; cfg.charges.serviceLabel = P; cfg.charges.taxLabel = P; cfg.devices[0].name = 'Tab' + P;
  window.Bangku.saveConfig(cfg);
  localStorage.setItem('bangku:customers', JSON.stringify([{ id: 'c1', name: 'Cust' + P, phone: '0812' + P, notes: P, consent: true, visits: 1, spent: 1000, points: 0, lastVisit: new Date().toISOString(), createdAt: new Date().toISOString() }]));
  localStorage.setItem('bangku:sales', JSON.stringify([{ code: 'B' + P, queue: 1, name: 'N' + P, customerId: 'c1', cashier: 'K' + P, cashierId: 'u1', type: 'dine', table: P, subtotal: 1, discount: 0, service: 0, tax: 0, total: 1, method: 'qris', methodLabel: 'M' + P, bank: P, received: 1, change: 0, paidAt: new Date().toISOString(), shiftId: 's', status: 'paid', lines: [{ id: 1, name: 'L' + P, categoryId: 'kopi', qty: 1, price: 1, opts: [P] }] }]));
  localStorage.setItem('bangku:shifts', JSON.stringify([{ id: 's', day: '2026-10-24', name: 'S' + P, cashier: 'C' + P, openedAt: new Date().toISOString(), closedAt: new Date().toISOString(), float: 1, sales: [], cash: [{ kind: 'out', amount: 1, reason: P, approvedBy: P }], summary: { count: 0, by: {}, banks: {}, cash: 0, total: 0, cashIn: 0, cashOut: 1, expected: 0 }, counted: 0, variance: -1, varianceReason: P, approvedBy: '', floatLeft: 0, deposit: 0, edc: { pos: { ['Q' + P]: 1 }, edc: {}, note: P } }]));
  localStorage.setItem('bangku:handover', JSON.stringify({ amount: 5, from: P }));
  localStorage.setItem('bangku:held', JSON.stringify([{ uid: 'h' + P, queue: 3, code: 'B' + P, name: 'Hold' + P, type: 'dine', table: P, total: 1000, createdAt: new Date(Date.now() - 5 * 86400000).toISOString(), carriedFrom: P, lines: [{ id: 1, qty: 1, picks: {}, note: P, printed: 1 }], discount: { kind: 'none', value: 0 } }]));
  localStorage.setItem('bangku:adminSession', JSON.stringify({ staffId: 'u4' }));
}, P);
const pages = ['dasbor','transaksi','shift','laporan','menu','varian','pelanggan','karyawan','pengaturan/toko','pengaturan/pajak','pengaturan/pesanan','pengaturan/bayar','pengaturan/shift','pengaturan/aturan','pengaturan/perangkat','pengaturan/data'];
const hit = [];
for (const pg of pages) { await p.goto(BASE + 'admin.html#/' + pg); await p.reload(); await p.waitForTimeout(150);
  if (pg === 'transaksi' || pg === 'shift' || pg === 'pelanggan' || pg === 'menu') { const r = await p.$('tr[data-open]'); if (r) { await r.click(); await p.waitForTimeout(150); } }
  if (await p.evaluate(() => { const v = window.__xss; window.__xss = 0; return v; })) hit.push('admin ' + pg); }
await p.goto(BASE + 'admin.html#/transaksi'); await p.reload(); await p.click('#t-method [data-v="open"]'); await p.click('tr[data-open="0"]'); await p.waitForTimeout(150);
if (await p.evaluate(() => { const v = window.__xss; window.__xss = 0; return v; })) hit.push('admin open bill');
await p.goto(BASE + 'masuk.html'); await p.evaluate(() => localStorage.setItem('bangku:device', JSON.stringify('d1'))); await p.reload(); await p.waitForTimeout(150);
if (await p.evaluate(() => { const v = window.__xss; window.__xss = 0; return v; })) hit.push('masuk');
await p.click('[data-staff="u1"]'); await p.waitForTimeout(100); if (await p.evaluate(() => { const v = window.__xss; window.__xss = 0; return v; })) hit.push('masuk PIN');
await p.evaluate(() => localStorage.setItem('bangku:session', JSON.stringify({ staffId: 'u1' })));
await p.goto(BASE + 'kasir.html'); await p.waitForTimeout(200);
if (await p.evaluate(() => { const v = window.__xss; window.__xss = 0; return v; })) hit.push('kasir buka shift');
await p.click('#open-ok').catch(() => {}); await p.waitForTimeout(100);
await p.click('[data-id="1"]').catch(() => {}); await p.waitForTimeout(150);
if (await p.evaluate(() => { const v = window.__xss; window.__xss = 0; return v; })) hit.push('kasir varian');
await p.click('#opt-save').catch(() => {}); await p.fill('#customer', 'Cu'); await p.dispatchEvent('#customer', 'input'); await p.waitForTimeout(100);
if (await p.evaluate(() => { const v = window.__xss; window.__xss = 0; return v; })) hit.push('kasir saran pelanggan');
await p.click('#discount-btn').catch(() => {}); await p.waitForTimeout(100); await p.keyboard.press('Escape');
await p.click('[data-method="qris"]').catch(() => {}); await p.click('#checkout').catch(() => {}); await p.waitForTimeout(100);
await p.click('#pay-ok').catch(() => {}); await p.waitForTimeout(200);
if (await p.evaluate(() => { const v = window.__xss; window.__xss = 0; return v; })) hit.push('kasir bayar/struk');
await p.click('#receipt-close').catch(() => {});
await p.click('#held-btn').catch(() => {}); await p.waitForTimeout(100);
if (await p.evaluate(() => { const v = window.__xss; window.__xss = 0; return v; })) hit.push('kasir daftar open bill');
await p.keyboard.press('Escape');
await p.click('#shift-chip').catch(() => {}); await p.waitForTimeout(100); await p.click('#shift-dlg [data-s="out"]').catch(() => {}); await p.waitForTimeout(100);
if (await p.evaluate(() => { const v = window.__xss; window.__xss = 0; return v; })) hit.push('kasir kas');
console.log(hit.length ? '✗ XSS terjadi: ' + hit.join(', ') : '✓ tidak ada XSS di semua halaman yang diuji');
if (errors.length) console.log('✗ error JS: ' + errors.slice(0, 5).join(' | '));
await b.close();
process.exit(hit.length || errors.length ? 1 : 0);
