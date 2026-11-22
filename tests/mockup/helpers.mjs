// Bantuan uji bersama: masuk kasir lewat masuk.html (daftar perangkat, pilih kasir, PIN) lalu buka shift.
import { dirname, join } from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
// BASE bisa diarahkan ke salinan lain (mis. versi Artifact) lewat env MOCKUP_BASE.
// pathToFileURL: di Windows 'file://' + path menghasilkan file://C:\... yang tidak valid.
export const BASE = process.env.MOCKUP_BASE || pathToFileURL(join(here, '../../docs/mockup') + '/').href;
export const STAFF = { Rani: { id: 'u1', pin: '1111' }, Dimas: { id: 'u2', pin: '2222' }, Sinta: { id: 'u3', pin: '3333' }, Owner: { id: 'u4', pin: '9999' } };
export const PAIR_CODE = '246810';

export async function typePin(page, pin) {
    for (const d of pin) await page.tap(`#pin-dlg [data-p="${d}"]`);
    await page.waitForTimeout(80);
}

/* Mulai dari kosong: hapus data perangkat, daftarkan tablet, masuk sebagai kasir. */
export async function freshLogin(page, name = 'Rani') {
    await page.goto(BASE + 'masuk.html');
    await page.evaluate(() => localStorage.clear());
    await login(page, name);
}

export async function login(page, name = 'Rani') {
    if (!/masuk\.html/.test(page.url())) await page.goto(BASE + 'masuk.html');
    await page.waitForSelector('#step-pair:not([hidden]), #step-people:not([hidden])');
    if (await page.isVisible('#step-pair')) {
        for (const d of PAIR_CODE) await page.tap(`#pair-keypad [data-k="${d}"]`);
    }
    await page.tap(`[data-staff="${STAFF[name].id}"]`);
    for (const d of STAFF[name].pin) await page.tap(`#pin-keypad [data-k="${d}"]`);
    await page.waitForURL(/kasir\.html/);
    await page.waitForSelector('#grid .item');
}

export async function openShift(page, { shift = 'Pagi', notes = { 100000: 1, 50000: 2, 20000: 2, 10000: 1, 5000: 2 } } = {}) {
    await page.tap(`#open-shift [data-shift="${shift}"]`);
    for (const [d, n] of Object.entries(notes)) for (let i = 0; i < n; i++) await page.tap(`#open-denoms [data-d="${d}"][data-step="1"]`);
    await page.tap('#open-ok');
    await page.waitForTimeout(80);
}
