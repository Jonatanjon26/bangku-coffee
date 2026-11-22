/*
 * Konfigurasi & data bersama mockup Bangku Coffee (kasir, masuk, admin).
 * Semua yang bisa berubah diatur di panel admin dan disimpan di perangkat (localStorage).
 * Di aplikasi Laravel nanti: tabel database + halaman Filament dengan struktur yang sama.
 * Catatan: PIN & kata sandi di sini teks biasa hanya untuk mockup — di aplikasi disimpan ter-hash di server.
 */
(() => {
    'use strict';

    // Hak akses yang dikenal sistem (tetap). Peran & pembagian haknya diatur di admin.
    const PERMISSIONS = [
        { id: 'pos', label: 'Pakai aplikasi kasir' },
        { id: 'approve', label: 'Menyetujui (selisih kas, kas keluar, diskon besar, refund)' },
        { id: 'admin', label: 'Masuk panel admin' },
        { id: 'reports', label: 'Lihat transaksi, shift & laporan' },
        { id: 'menu', label: 'Kelola menu & varian' },
        { id: 'customers', label: 'Kelola pelanggan' },
        { id: 'staff', label: 'Kelola karyawan & peran' },
        { id: 'settings', label: 'Ubah pengaturan toko' },
    ];

    const DEFAULT_CONFIG = {
        version: 1,
        store: { name: 'Bangku Coffee', address: 'Jl. Contoh No. 1, Medan', phone: '0812-0000-0000', footer: 'Terima kasih, sampai ketemu lagi!' },
        // Service charge dihitung dari (subtotal − diskon); PB1 dari (subtotal − diskon + service).
        charges: { serviceEnabled: false, serviceRate: 5, serviceLabel: 'Service', taxEnabled: false, taxRate: 10, taxLabel: 'PB1' },
        orderTypes: [
            { id: 'dine', label: 'Di tempat', active: true, needsTable: true, service: true },
            { id: 'take', label: 'Bawa pulang', active: true, needsTable: false, service: false },
            { id: 'online', label: 'Ojek online', active: false, needsTable: false, service: false },
        ],
        categories: [
            { id: 'kopi', name: 'Kopi', station: 'Bar' },
            { id: 'nonkopi', name: 'Non-kopi', station: 'Bar' },
            { id: 'teh', name: 'Teh', station: 'Bar' },
            { id: 'makanan', name: 'Makanan', station: 'Dapur' },
            { id: 'camilan', name: 'Camilan', station: 'Dapur' },
        ],
        // printAlways: pilihan bawaan tetap ditulis di tiket (mis. Es/Panas selalu penting bagi barista).
        modifierGroups: [
            { id: 'size', name: 'Ukuran', printAlways: false, options: [{ name: 'Regular', price: 0 }, { name: 'Large', price: 5000 }] },
            { id: 'temp', name: 'Suhu', printAlways: true, options: [{ name: 'Es', price: 0 }, { name: 'Panas', price: 0 }] },
            { id: 'sugar', name: 'Gula', printAlways: false, options: [{ name: 'Normal', price: 0 }, { name: 'Kurang manis', price: 0 }, { name: 'Tanpa gula', price: 0 }] },
            { id: 'shot', name: 'Tambahan', printAlways: false, options: [{ name: 'Tanpa', price: 0 }, { name: 'Extra shot', price: 5000 }, { name: 'Oat milk', price: 6000 }] },
        ],
        items: [
            { id: 1, name: 'Kopi Susu Bangku', categoryId: 'kopi', price: 22000, emoji: '☕', groups: ['size', 'temp', 'sugar', 'shot'], active: true, soldOut: false, best: true },
            { id: 2, name: 'Es Kopi Aren', categoryId: 'kopi', price: 23000, emoji: '🧋', groups: ['size', 'temp', 'sugar', 'shot'], active: true, soldOut: false, best: false },
            { id: 3, name: 'Americano', categoryId: 'kopi', price: 20000, emoji: '☕', groups: ['size', 'temp', 'sugar', 'shot'], active: true, soldOut: false, best: false },
            { id: 4, name: 'Cappuccino', categoryId: 'kopi', price: 25000, emoji: '☕', groups: ['size', 'temp', 'sugar', 'shot'], active: true, soldOut: false, best: false },
            { id: 5, name: 'Caffe Latte', categoryId: 'kopi', price: 25000, emoji: '🥛', groups: ['size', 'temp', 'sugar', 'shot'], active: true, soldOut: false, best: false },
            { id: 6, name: 'Kopi Tubruk', categoryId: 'kopi', price: 15000, emoji: '🫖', groups: ['sugar'], active: true, soldOut: false, best: false },
            { id: 7, name: 'V60 Manual Brew', categoryId: 'kopi', price: 28000, emoji: '⚗️', groups: ['temp'], active: true, soldOut: false, best: false },
            { id: 8, name: 'Matcha Latte', categoryId: 'nonkopi', price: 27000, emoji: '🍵', groups: ['size', 'temp', 'sugar'], active: true, soldOut: false, best: false },
            { id: 9, name: 'Cokelat Bangku', categoryId: 'nonkopi', price: 24000, emoji: '🍫', groups: ['size', 'temp', 'sugar'], active: true, soldOut: false, best: false },
            { id: 10, name: 'Red Velvet', categoryId: 'nonkopi', price: 24000, emoji: '🥤', groups: ['size', 'temp', 'sugar'], active: true, soldOut: false, best: false },
            { id: 11, name: 'Teh Tarik', categoryId: 'teh', price: 18000, emoji: '🫖', groups: ['size', 'temp', 'sugar'], active: true, soldOut: false, best: false },
            { id: 12, name: 'Lemon Tea', categoryId: 'teh', price: 16000, emoji: '🍋', groups: ['size', 'temp', 'sugar'], active: true, soldOut: false, best: false },
            { id: 13, name: 'Croissant Butter', categoryId: 'makanan', price: 20000, emoji: '🥐', groups: [], active: true, soldOut: false, best: false },
            { id: 14, name: 'Roti Bakar Cokelat Keju', categoryId: 'makanan', price: 18000, emoji: '🍞', groups: [], active: true, soldOut: false, best: false },
            { id: 15, name: 'Nasi Goreng Bangku', categoryId: 'makanan', price: 28000, emoji: '🍛', groups: [], active: true, soldOut: true, best: false },
            { id: 16, name: 'Kentang Goreng', categoryId: 'camilan', price: 20000, emoji: '🍟', groups: [], active: true, soldOut: false, best: false },
            { id: 17, name: 'Pisang Goreng', categoryId: 'camilan', price: 15000, emoji: '🍌', groups: [], active: true, soldOut: false, best: false },
            { id: 18, name: 'Air Mineral', categoryId: 'camilan', price: 8000, emoji: '💧', groups: [], active: true, soldOut: false, best: false },
        ],
        // kind: cash = laci uang (ada kembalian), edc = mesin EDC/QRIS (dicocokkan dengan settlement).
        payments: [
            { id: 'cash', label: 'Tunai', kind: 'cash', needsBank: false, active: true },
            { id: 'qris', label: 'QRIS', kind: 'edc', needsBank: false, active: true },
            { id: 'debit', label: 'Debit', kind: 'edc', needsBank: true, active: true },
            { id: 'credit', label: 'Kartu kredit', kind: 'edc', needsBank: true, active: false },
        ],
        banks: ['BCA', 'Mandiri', 'BRI', 'BNI', 'BSI', 'CIMB', 'Permata', 'Lainnya'],
        discounts: { presets: ['5%', '10%', '20%', '5000', '10000'], approvalAbovePct: 20 },
        shifts: [{ name: 'Pagi', from: '07:00', to: '15:00' }, { name: 'Sore', from: '15:00', to: '22:00' }],
        denominations: [100000, 50000, 20000, 10000, 5000, 2000, 1000],
        cashReasons: { out: ['Es batu', 'Galon', 'Susu', 'Gas', 'Cup/plastik', 'Lainnya'], in: ['Tambah uang receh', 'Titipan owner', 'Lainnya'] },
        refundReasons: ['Salah input', 'Pelanggan batal', 'Item habis', 'Komplain'],
        voidReasons: ['Pelanggan batal', 'Tidak dibayar / pelanggan pergi', 'Salah input', 'Digabung ke pesanan lain'],
        roles: [
            { id: 'owner', name: 'Owner', perms: PERMISSIONS.map((p) => p.id) },
            { id: 'manager', name: 'Kepala toko', perms: ['pos', 'approve', 'admin', 'reports', 'menu', 'customers'] },
            { id: 'cashier', name: 'Kasir', perms: ['pos'] },
            { id: 'barista', name: 'Barista', perms: [] },
        ],
        staff: [
            { id: 'u1', name: 'Rani', roleId: 'cashier', pin: '1111', email: '', password: '', active: true },
            { id: 'u2', name: 'Dimas', roleId: 'cashier', pin: '2222', email: '', password: '', active: true },
            { id: 'u3', name: 'Sinta', roleId: 'manager', pin: '3333', email: 'sinta@bangkucoffee.id', password: 'password', active: true },
            { id: 'u4', name: 'Owner', roleId: 'owner', pin: '9999', email: 'owner@bangkucoffee.id', password: 'password', active: true },
        ],
        policy: {
            varianceApprovalAbove: 20000,
            cashOutApprovalAbove: 100000,
            refundNeedsApproval: true,
        // Open bill (simpan, bayar nanti) boleh dialihkan ke shift/hari berikutnya; lewat hari perlu persetujuan.
        carryOpenBills: true,
        openBillDayApproval: true,
        openBillMaxDays: 3,
            requireCustomerName: true,
            pinLength: 4,
        maxPinAttempts: 5,
            lockSeconds: 60,
            autoLockMinutes: 5,
        },
        customers: { enabled: true, askPhone: true, loyaltyEnabled: false, pointPer: 10000 },
        printing: { cols: 32, ticket: true, receiptDefault: true },
        devices: [{ id: 'd1', name: 'Tablet Kasir 1', registeredAt: '2026-10-01T08:00:00' }],
        // Kode sekali pakai untuk mendaftarkan tablet baru (dibuat ulang di admin › Pengaturan › Perangkat).
        pairing: { code: '246810', deviceName: 'Tablet Kasir 2' },
    };

    const KEY = 'bangku:';
    const data = {
        get(key, fallback) { try { return JSON.parse(localStorage.getItem(KEY + key)) ?? fallback; } catch { return fallback; } },
        set(key, value) { try { localStorage.setItem(KEY + key, JSON.stringify(value)); } catch { /* mode privat / penyimpanan penuh */ } },
        remove(key) { try { localStorage.removeItem(KEY + key); } catch { /* abaikan */ } },
    };

    const clone = (v) => JSON.parse(JSON.stringify(v));
    // Bagian yang belum ada di konfigurasi tersimpan diisi dari bawaan (aman saat skema bertambah).
    function config() {
        const saved = data.get('config', null);
        if (!saved) return clone(DEFAULT_CONFIG);
        const merged = clone(DEFAULT_CONFIG);
        for (const k of Object.keys(saved)) {
            merged[k] = saved[k] && typeof saved[k] === 'object' && !Array.isArray(saved[k]) && merged[k] && !Array.isArray(merged[k]) ? { ...merged[k], ...saved[k] } : saved[k];
        }
        return merged;
    }
    const saveConfig = (c) => data.set('config', c);
    const resetConfig = () => data.remove('config');

    const roleOf = (cfg, staff) => cfg.roles.find((r) => r.id === staff.roleId) || { id: '', name: '-', perms: [] };
    const can = (cfg, staff, perm) => !!staff && staff.active && roleOf(cfg, staff).perms.includes(perm);

    /* Sesi kasir di perangkat ini (setelah PIN benar). */
    const session = {
        get() { return data.get('session', null); },
        start(staff) { data.set('session', { staffId: staff.id, at: new Date().toISOString() }); },
        end() { data.remove('session'); },
        staff(cfg) { const s = data.get('session', null); return s ? cfg.staff.find((x) => x.id === s.staffId && x.active) || null : null; },
    };

    /* Pelanggan (database sederhana di perangkat). */
    const normPhone = (p) => String(p || '').replace(/[^\d+]/g, '').replace(/^\+?62/, '0');
    const customers = {
        all() { return data.get('customers', []); },
        save(list) { data.set('customers', list); },
        find(q) {
            const s = String(q || '').trim().toLowerCase();
            if (s.length < 2) return [];
            const digits = normPhone(s);
            return customers.all().filter((c) => c.name.toLowerCase().includes(s) || (digits.length >= 3 && normPhone(c.phone).includes(digits))).slice(0, 6);
        },
        upsert(c) {
            const list = customers.all();
            const i = list.findIndex((x) => x.id === c.id);
            if (i >= 0) list[i] = { ...list[i], ...c };
            else list.push({ id: 'c' + Date.now() + Math.floor(Math.random() * 1000), visits: 0, spent: 0, points: 0, lastVisit: null, createdAt: new Date().toISOString(), ...c });
            customers.save(list);
            return i >= 0 ? list[i] : list[list.length - 1];
        },
        recordVisit(id, amount, pointPer) {
            const list = customers.all();
            const c = list.find((x) => x.id === id);
            if (!c) return;
            c.visits += 1;
            c.spent += amount;
            c.lastVisit = new Date().toISOString();
            if (pointPer) c.points += Math.floor(amount / pointPer);
            customers.save(list);
        },
    };

    const rp = (n) => 'Rp ' + Math.round(n).toLocaleString('id-ID');
    const esc = (s) => String(s ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    const todayKey = (d = new Date()) => d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');

    /* Tema: ikuti sistem; pilihan pengguna disimpan per perangkat. */
    function initTheme(button) {
        const t = data.get('theme', '');
        if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t;
        if (button) {
            button.addEventListener('click', () => {
                const root = document.documentElement;
                const cur = root.dataset.theme || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                root.dataset.theme = cur === 'dark' ? 'light' : 'dark';
                data.set('theme', root.dataset.theme);
            });
        }
    }

    window.Bangku = { PERMISSIONS, DEFAULT_CONFIG, data, config, saveConfig, resetConfig, roleOf, can, session, customers, normPhone, rp, esc, todayKey, initTheme, clone };
})();
