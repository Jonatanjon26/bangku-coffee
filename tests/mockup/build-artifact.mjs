// Membuat versi Artifact claude.ai dari mockup: halaman pembuka + kasir/masuk/admin + shared/*.
// Di Artifact, Bluetooth & dialog cetak diblokir bingkai → kasir hanya menampilkan pratinjau struk.
// Jalankan: node build-artifact.mjs [folder-keluaran]   (bawaan: output/artifact)
import { mkdirSync, readFileSync, writeFileSync, copyFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const SRC = join(here, '../../docs/mockup');
const OUT = process.argv[2] || join(here, 'output/artifact');
mkdirSync(join(OUT, 'shared'), { recursive: true });
const must = (s, a, b) => { if (!s.includes(a)) throw new Error('tidak ditemukan: ' + a.slice(0, 70)); return s.split(a).join(b); };

let kasir = readFileSync(join(SRC, 'kasir.html'), 'utf8');
kasir = must(kasir, 'const hasWebBluetooth = !!(navigator.bluetooth && navigator.bluetooth.requestDevice);', 'const hasWebBluetooth = false;');
kasir = must(kasir, 'const isAndroid = /Android/i.test(navigator.userAgent);', 'const isAndroid = false;');
kasir = must(kasir, "if (!hasWebBluetooth) setPrinterStatus('', 'Bluetooth tidak didukung browser');", "if (!hasWebBluetooth) setPrinterStatus('', 'Demo · pratinjau struk');");
kasir = must(kasir, "if (!hasWebBluetooth) { toast('Browser ini tidak mendukung Bluetooth. Pakai Chrome di tablet Android.'); return false; }",
    "if (!hasWebBluetooth) { toast('Versi demo: cetak Bluetooth hanya di file kasir.html lokal (Chrome tablet).'); return false; }");
kasir = must(kasir, "'<b>Browser ini tidak mendukung Bluetooth.</b> Di tablet Samsung pakai <b>Chrome</b>' + (isAndroid ? ', atau tombol <b>RawBT</b>' : '') + '.'",
    "'<b>Versi demo.</b> Ini pratinjau persis isi kertas 57 mm. Cetak Bluetooth sungguhan pakai file <b>kasir.html</b> lokal di Chrome tablet.'");
kasir = must(kasir, '<button class="btn" type="button" id="print-browser">Dialog cetak</button>', '<button class="btn" type="button" id="print-browser" hidden>Dialog cetak</button>');
writeFileSync(join(OUT, 'kasir.html'), kasir);
for (const f of ['masuk.html', 'admin.html', 'shared/config.js', 'shared/base.css']) copyFileSync(join(SRC, f), join(OUT, f));

// Halaman pembuka (halaman utama Artifact).
const cfg = readFileSync(join(SRC, 'shared/config.js'), 'utf8');
const code = (cfg.match(/pairing: \{ code: '(\d+)'/) || [])[1] || '';
writeFileSync(join(OUT, 'index.html'), `<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Mockup Bangku Coffee</title>
<link rel="stylesheet" href="shared/base.css">
<style>
.wrap { max-width: 880px; margin: 0 auto; padding: 40px max(16px, env(safe-area-inset-right)) 40px max(16px, env(safe-area-inset-left)); display: grid; gap: 24px; }
.hero h1 { font-size: 28px; } .hero p { color: var(--muted-foreground); margin-top: 6px; max-width: 60ch; }
.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 16px; }
.card { display: grid; gap: 10px; padding: 20px; border: 1px solid var(--border); border-radius: 14px; background: var(--card); text-decoration: none; box-shadow: var(--shadow-xs); }
.card:hover { border-color: var(--ring); background: var(--accent); }
.card .ic { width: 44px; height: 44px; border-radius: 12px; display: grid; place-items: center; background: var(--primary); color: var(--primary-foreground); font-size: 22px; }
.card h2 { font-size: 18px; } .card p { color: var(--muted-foreground); }
.card ul { margin: 0; padding-left: 18px; color: var(--muted-foreground); font-size: 13px; display: grid; gap: 2px; }
.card b.go { color: var(--primary); }
.note { padding: 12px 14px; border-radius: var(--radius); background: var(--muted); font-size: 13px; color: var(--muted-foreground); }
code { font-family: var(--mono); color: var(--foreground); }
</style>
</head>
<body>
<div class="wrap">
  <div class="hero"><h1>Mockup Bangku Coffee</h1><p>Aplikasi kasir untuk tablet dan panel admin. Semua menu, harga, pajak, metode bayar, shift, aturan, karyawan, dan pelanggan diatur di panel admin, lalu langsung dipakai kasir.</p></div>
  <div class="cards">
    <a class="card" href="masuk.html"><span class="ic">☕</span><h2>Aplikasi kasir (tablet)</h2><p>Daftar tablet → pilih kasir → PIN → buka shift → jualan → tutup shift.</p>
      <ul><li>Kode daftar tablet: <code>${code}</code></li><li>PIN: Rani <code>1111</code> · Dimas <code>2222</code> · Sinta <code>3333</code> · Owner <code>9999</code></li></ul><b class="go">Buka kasir →</b></a>
    <a class="card" href="admin.html"><span class="ic">⚙</span><h2>Panel admin</h2><p>Dasbor, transaksi & refund, shift & setoran, laporan, menu, varian, pelanggan, karyawan & peran, pengaturan.</p>
      <ul><li>Owner: <code>owner@bangkucoffee.id</code> / <code>password</code></li><li>Kepala toko: <code>sinta@bangkucoffee.id</code> / <code>password</code></li><li>Isi data contoh 14 hari dari dasbor</li></ul><b class="go">Buka panel admin →</b></a>
  </div>
  <p class="note">Data tersimpan di browser ini saja dan dipakai bersama oleh kasir & admin. Di versi ini struk hanya pratinjau; cetak Bluetooth sungguhan memakai file mockup lokal di Chrome tablet.</p>
</div>
</body>
</html>
`);
console.log('Artifact siap di ' + OUT);
