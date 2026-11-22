/*
 * Cetak di tablet kasir: byte ESC/POS dibuat di server (App\Support\Printing), modul ini hanya mengirim.
 * Cara cetak dipilih per tablet (localStorage):
 *  - bluetooth: Web Bluetooth (Chrome Android) → printer Bluetooth LE
 *  - rawbt:     aplikasi RawBT (Android) → printer Bluetooth biasa (SPP)
 *  - dialog:    dialog cetak browser (printer yang terpasang di sistem)
 */
const SERVICES = [
    '000018f0-0000-1000-8000-00805f9b34fb',
    'e7810a71-73ae-499d-8c15-faa9aef0c3f2',
    '49535343-fe7d-4ae5-8fa9-9fafd205e455',
    '0000ff00-0000-1000-8000-00805f9b34fb',
    '0000ffe0-0000-1000-8000-00805f9b34fb',
    '0000fee7-0000-1000-8000-00805f9b34fb',
];
const KEY = 'bangku:printMethod';
const CHUNK = 100;

const decode = (b64) => Uint8Array.from(atob(b64), (c) => c.charCodeAt(0));
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function register(Alpine) {
    const text = window.bangkuPrinterText || {};
    const hasBluetooth = !!(navigator.bluetooth && navigator.bluetooth.requestDevice);
    const isAndroid = /Android/i.test(navigator.userAgent);
    const saved = (() => { try { return localStorage.getItem(KEY); } catch (e) { return null; } })();

    Alpine.store('printer', {
        hasBluetooth,
        isAndroid,
        method: saved || (hasBluetooth ? 'bluetooth' : (isAndroid ? 'rawbt' : 'dialog')),
        status: '',
        label: text.none,
        device: null,
        char: null,
        preview: null,
        pending: null,

        setMethod(m) {
            this.method = m;
            try { localStorage.setItem(KEY, m); } catch (e) { /* mode privat */ }
            this.refreshLabel();
        },
        refreshLabel() {
            if (this.method === 'rawbt') { this.status = 'ok'; this.label = text.rawbt; return; }
            if (this.method === 'dialog') { this.status = 'ok'; this.label = text.dialog; return; }
            this.status = this.char ? 'ok' : '';
            this.label = this.char ? (this.device?.name || text.none) : text.none;
        },

        async connect() {
            if (!hasBluetooth) { this.toast(text.unsupported); return false; }
            try {
                this.status = 'busy'; this.label = text.searching;
                if (!this.device) {
                    this.device = await navigator.bluetooth.requestDevice({ acceptAllDevices: true, optionalServices: SERVICES });
                    this.device.addEventListener('gattserverdisconnected', () => { this.char = null; this.status = ''; this.label = text.disconnected; });
                }
                const server = this.device.gatt.connected ? this.device.gatt : await this.device.gatt.connect();
                this.char = null;
                for (const uuid of SERVICES) {
                    let service;
                    try { service = await server.getPrimaryService(uuid); } catch (e) { continue; }
                    for (const c of await service.getCharacteristics()) {
                        if (c.properties.writeWithoutResponse || c.properties.write) { this.char = c; break; }
                    }
                    if (this.char) break;
                }
                if (!this.char) throw new Error(text.noWrite);
                this.refreshLabel();
                return true;
            } catch (e) {
                if (e && e.name === 'NotFoundError') { this.refreshLabel(); return false; }
                this.device = null; this.char = null; this.status = ''; this.label = text.none;
                this.toast((e && e.message) || text.failed);
                return false;
            }
        },

        async sendBluetooth(bytes) {
            if (!this.char && this.device && !(await this.connect())) return false;
            if (!this.char) return false;
            try {
                this.status = 'busy'; this.label = text.printing;
                for (let i = 0; i < bytes.length; i += CHUNK) {
                    const part = bytes.slice(i, i + CHUNK);
                    if (this.char.properties.writeWithoutResponse && this.char.writeValueWithoutResponse) await this.char.writeValueWithoutResponse(part);
                    else await this.char.writeValue(part);
                    await sleep(25);
                }
                this.refreshLabel();
                return true;
            } catch (e) {
                this.char = null; this.status = ''; this.label = text.failed;
                return false;
            }
        },

        rawbt(job) { window.location.href = 'rawbt:base64,' + job.escpos; },

        dialog(job) {
            const area = document.getElementById('print-area');
            if (!area) return;
            this.preview = job;
            requestAnimationFrame(() => window.print());
        },

        /**
         * Cetak satu kiriman. defer = jangan buka dialog/pratinjau sekarang (mis. saat layar kembalian tampil);
         * panggil flush() setelahnya.
         */
        async print(job, { defer = false } = {}) {
            if (!job) return false;
            if (this.method === 'rawbt') { this.rawbt(job); return true; }
            if (this.method === 'bluetooth' && (await this.sendBluetooth(decode(job.escpos)))) return true;
            if (defer) { this.pending = job; return false; }
            this.openPreview(job);
            return false;
        },
        flush() { const job = this.pending; this.pending = null; if (job) this.openPreview(job); },

        openPreview(job) {
            this.preview = job;
            if (this.method === 'dialog') { this.dialog(job); return; }
            document.getElementById('receipt-dlg')?.showModal();
        },
        async printPreviewBluetooth() {
            if (!this.preview) return;
            if ((this.char || (await this.connect())) && (await this.sendBluetooth(decode(this.preview.escpos)))) {
                document.getElementById('receipt-dlg')?.close();
                this.toast(text.printed);
            }
        },

        toast(message) { window.dispatchEvent(new CustomEvent('toast', { detail: { message } })); },
    });

    Alpine.store('printer').refreshLabel();
}

if (window.Alpine) register(window.Alpine);
else document.addEventListener('alpine:init', () => register(window.Alpine));
