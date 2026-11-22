// Uji sentuh di ukuran layar Galaxy Tab (S9/A9+/A9, mendatar  tegak): target >= 44px, alur kasir dengan ketukan,
// tombol Bayar selalu terlihat, tanpa overflow, tanpa pull-to-refresh. Jalankan: node touch.mjs
import { chromium } from 'playwright';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { BASE, login, openShift, PAIR_CODE } from './helpers.mjs';
const here = dirname(fileURLToPath(import.meta.url));
const OUT = process.argv[2] || join(here, 'output');
mkdirSync(OUT, { recursive: true });
const DEVICES=[['Tab S9/A9+ mendatar',1280,800],['Tab S9/A9+ tegak',800,1280],['Tab A9 mendatar',893,533],['Tab A9 tegak',533,893]];
const b=await chromium.launch(); let problems=0;
const small=(page)=>page.evaluate(()=>{
  const vis=e=>{const r=e.getBoundingClientRect(),s=getComputedStyle(e);return r.width>0&&r.height>0&&s.visibility!=='hidden'&&s.display!=='none'&&r.bottom>0&&r.top<innerHeight;};
  const out=[]; for (const e of document.querySelectorAll('button, [role=tab], input, a[href]')) { if(!vis(e)||e.closest('dialog:not([open])')) continue; const r=e.getBoundingClientRect(); const isInput=e.tagName==='INPUT'; if ((isInput && r.height<44) || (!isInput && (r.width<44||r.height<44))) out.push(`${e.tagName}${e.id?'#'+e.id:''} "${(e.textContent||e.placeholder||e.getAttribute('aria-label')||'').trim().slice(0,22)}" ${Math.round(r.width)}x${Math.round(r.height)}`);} return out;});
for (const [name,w,h] of DEVICES) {
  const ctx=await b.newContext({viewport:{width:w,height:h}, hasTouch:true, isMobile:true, deviceScaleFactor:1.5, userAgent:'Mozilla/5.0 (Linux; Android 14; SM-X210) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36'});
  const p=await ctx.newPage(); const errors=[]; p.on('pageerror',e=>errors.push(String(e)));
  const issues=[];
  // layar masuk: daftar perangkat → pilih kasir → PIN
  await p.goto(BASE+'masuk.html');
  issues.push(...(await small(p)).map(x=>'kecil (daftar perangkat): '+x));
  { const ov=await p.evaluate(()=>document.documentElement.scrollWidth-innerWidth); if(ov>1) issues.push('overflow masuk '+ov+'px'); }
  await p.screenshot({path:`${OUT}/${w}x${h}-masuk-daftar.png`});
  for (const d of PAIR_CODE) await p.tap(`#pair-keypad [data-k="${d}"]`);
  await p.waitForTimeout(100);
  issues.push(...(await small(p)).map(x=>'kecil (pilih kasir): '+x));
  await p.screenshot({path:`${OUT}/${w}x${h}-masuk-kasir.png`});
  await p.tap('[data-staff="u1"]'); await p.waitForTimeout(100);
  issues.push(...(await small(p)).map(x=>'kecil (PIN): '+x));
  if (!(await p.evaluate(()=>{const r=document.querySelector('#pin-keypad [data-k="0"]').getBoundingClientRect(); return r.bottom<=innerHeight;}))) issues.push('keypad PIN terpotong');
  await p.screenshot({path:`${OUT}/${w}x${h}-masuk-pin.png`});
  await p.tap('#pin-keypad [data-k="cancel"]');
  await login(p, 'Rani');
  issues.push(...(await small(p)).map(x=>'kecil (buka shift): '+x));
  await p.screenshot({path:`${OUT}/${w}x${h}-buka-shift.png`});
  await openShift(p);
  const ov=await p.evaluate(()=>document.documentElement.scrollWidth-innerWidth); if(ov>1) issues.push('overflow '+ov+'px');
  if (await p.evaluate(()=>getComputedStyle(document.body).overscrollBehaviorY)!=='none') issues.push('pull-to-refresh tidak dimatikan');
  // alur dengan ketukan
  await p.tap('[data-id="1"]'); await p.tap('[data-group="size"] [data-i="1"]'); await p.screenshot({path:`${OUT}/${w}x${h}-varian.png`}); await p.tap('#opt-save');
  await p.tap('[data-id="13"]');
  const drawer = await p.isVisible('#cart-bar'); if (drawer) { await p.tap('#cart-bar'); await p.waitForTimeout(350); }
  await p.tap('[data-inc="1"]'); await p.tap('.line-main[data-edit="0"]'); await p.waitForTimeout(150);
  const editOpen=await p.evaluate(()=>document.querySelector('#opt-dlg').open); if(!editOpen) issues.push('ketuk baris tidak membuka varian');
  await p.tap('#opt-dlg button[value=cancel]'); await p.waitForTimeout(150);
  issues.push(...(await small(p)).map(x=>'kecil: '+x));
  // checkout terlihat tanpa scroll halaman
  const co=await p.evaluate(()=>{const r=document.querySelector('#checkout').getBoundingClientRect(); return r.bottom<=innerHeight && r.top>=0;}); if(!co) issues.push('tombol Bayar tidak terlihat tanpa scroll');
  // nama wajib, lalu bayar tunai lewat dialog (keypad + uang cepat)
  await p.fill('#customer', 'Rani');
  await p.screenshot({path:`${OUT}/${w}x${h}-pesanan.png`});
  await p.tap('#checkout'); await p.waitForTimeout(200);
  issues.push(...(await small(p)).map(x=>'kecil (bayar tunai): '+x));
  const payVisible=await p.evaluate(()=>{const vis=(sel)=>{const r=document.querySelector(sel).getBoundingClientRect(); const body=document.querySelector('#pay-body').getBoundingClientRect(); const dlg=document.querySelector('#pay-dlg').getBoundingClientRect(); return r.bottom<=Math.min(innerHeight, dlg.bottom, sel==='#pay-ok'?innerHeight:body.bottom)+1 && r.top>=0;}; return {ok:vis('#pay-ok'), change:vis('#change-live')};}); if(!payVisible.ok) issues.push('tombol Terima tidak terlihat di dialog bayar'); if(!payVisible.change) issues.push('kotak kembalian terpotong di dialog bayar');
  await p.screenshot({path:`${OUT}/${w}x${h}-bayar.png`});
  for (const k of ['5','0','000']) await p.tap(`#cash-keypad [data-k="${k}"]`);
  const recv=await p.textContent('#received'); if (recv!=='Rp 50.000') issues.push('keypad bayar: '+recv);
  await p.tap('#cash-quick [data-paid]'); await p.tap('#pay-ok'); await p.waitForTimeout(200);
  issues.push(...(await small(p)).map(x=>'kecil (kembalian): '+x));
  await p.screenshot({path:`${OUT}/${w}x${h}-kembalian.png`});
  await p.tap('#change-done'); await p.waitForTimeout(200);
  issues.push(...(await small(p)).map(x=>'kecil (cetak): '+x));
  await p.screenshot({path:`${OUT}/${w}x${h}-cetak.png`});
  await p.tap('#receipt-close'); await p.waitForTimeout(150);
  // debit: pilih bank
  await p.tap('[data-id="13"]'); if (drawer) { await p.tap('#cart-bar'); await p.waitForTimeout(350); }
  await p.fill('#customer', 'Budi'); await p.tap('[data-method="debit"]'); await p.tap('#checkout'); await p.waitForTimeout(200);
  issues.push(...(await small(p)).map(x=>'kecil (debit): '+x));
  await p.screenshot({path:`${OUT}/${w}x${h}-debit.png`});
  await p.tap('#pay-cancel'); await p.waitForTimeout(150);
  if (drawer && !(await p.evaluate(()=>document.querySelector('#order').classList.contains('open')))) { await p.tap('#cart-bar'); await p.waitForTimeout(350); }
  await p.tap('#clear'); await p.waitForTimeout(150);
  await p.tap('#confirm-ok'); await p.waitForTimeout(150);
  await p.tap('[data-id="13"]'); if (drawer) { await p.tap('#cart-bar'); await p.waitForTimeout(350);} await p.tap('#clear'); await p.waitForTimeout(150);
  const confirmOpen=await p.evaluate(()=>document.querySelector('#confirm-dlg').open); if(!confirmOpen) issues.push('konfirmasi kosongkan tidak muncul');
  // shift: ringkasan, kas keluar, tutup shift (hitung & hasil) — target sentuh & tombol utama terlihat
  await p.tap('#confirm-ok'); await p.waitForTimeout(150);
  const footVisible=(sel)=>p.evaluate((sel)=>{const r=document.querySelector(sel).getBoundingClientRect(); return r.top>=0 && r.bottom<=innerHeight+1;}, sel);
  await p.tap('#shift-chip'); await p.waitForTimeout(150);
  issues.push(...(await small(p)).map(x=>'kecil (shift): '+x));
  await p.tap('#shift-dlg [data-s="out"]'); await p.waitForTimeout(150);
  issues.push(...(await small(p)).map(x=>'kecil (kas): '+x)); if(!(await footVisible('#kas-ok'))) issues.push('tombol Simpan kas tidak terlihat');
  await p.screenshot({path:`${OUT}/${w}x${h}-kas.png`});
  await p.tap('#kas-cancel'); await p.waitForTimeout(150);
  await p.tap('#shift-chip'); await p.tap('#shift-close'); await p.waitForTimeout(150);
  issues.push(...(await small(p)).map(x=>'kecil (tutup: hitung): '+x)); if(!(await footVisible('#close-next'))) issues.push('tombol Lanjut tutup shift tidak terlihat');
  await p.screenshot({path:`${OUT}/${w}x${h}-tutup-hitung.png`});
  await p.tap('#close-denoms [data-d="100000"][data-step="1"]'); await p.tap('#close-next'); await p.waitForTimeout(150);
  issues.push(...(await small(p)).map(x=>'kecil (tutup: hasil): '+x)); if(!(await footVisible('#close-next'))) issues.push('tombol tutup shift tidak terlihat');
  await p.screenshot({path:`${OUT}/${w}x${h}-tutup-hasil.png`});
  const ov2=await p.evaluate(()=>document.documentElement.scrollWidth-innerWidth); if(ov2>1) issues.push('overflow (shift) '+ov2+'px');
  if (errors.length) issues.push('error JS: '+errors.join('; '));
  problems+=issues.length; console.log(`${issues.length?'✗':'✓'} ${name} (${w}x${h})${issues.length?'\n   - '+issues.join('\n   - '):''}`);
  await ctx.close();
}
console.log(problems?`${problems} masalah`:'semua lolos'); await b.close(); process.exit(problems?1:0);
