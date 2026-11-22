@props(['title' => null, 'css' => 'resources/css/pos.css', 'js' => null])
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ? $title.' · ' : '' }}{{ $storeName ?? config('app.name') }}</title>
    @include('partials.favicon')
    {{-- Tema per perangkat (sama dengan mockup): dipasang sebelum tampil supaya tidak berkedip. --}}
    <script>
        try { const t = JSON.parse(localStorage.getItem('bangku:theme')); if (t === 'dark' || t === 'light') document.documentElement.dataset.theme = t; } catch (e) {}
    </script>
    @vite(array_filter([$css, $js]))
    @livewireStyles
</head>
<body>
    {{ $slot }}

    <div class="toast" role="status" aria-live="polite"
         x-data="{ msg: '', action: null, show: false, timer: null }"
         x-on:toast.window="msg = $event.detail.message; action = $event.detail.action || null; show = true; clearTimeout(timer); timer = setTimeout(() => show = false, action ? 6000 : 2400)"
         x-bind:class="{ show }">
        <span x-text="msg"></span>
        <button type="button" id="toast-action" x-show="action" x-text="action?.label" x-on:click="window.dispatchEvent(new CustomEvent(action.event, { detail: action.detail })); show = false"></button>
    </div>

    @livewireScripts
    <script>
        window.bangkuHaptic = (ms = 8) => { try { navigator.vibrate && navigator.vibrate(ms); } catch (e) {} };
        window.bangkuToggleTheme = () => {
            const root = document.documentElement;
            const cur = root.dataset.theme || (matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            root.dataset.theme = cur === 'dark' ? 'light' : 'dark';
            try { localStorage.setItem('bangku:theme', JSON.stringify(root.dataset.theme)); } catch (e) {}
        };
        document.addEventListener('alpine:init', () => {
            // Keypad angka di layar: nilai dikirim ke server (aksi Livewire) saat panjangnya lengkap.
            Alpine.data('keypad', (length, action) => ({
                value: '',
                busy: false,
                shake: false,
                press(key) {
                    if (this.busy) return;
                    window.bangkuHaptic();
                    this.value = key === 'back' ? this.value.slice(0, -1) : (this.value + key).slice(0, length);
                    if (this.value.length < length) return;
                    this.busy = true;
                    this.$wire[action](this.value).finally(() => { this.value = ''; this.busy = false; });
                },
                failed() {
                    window.bangkuHaptic(60);
                    this.shake = false;
                    requestAnimationFrame(() => { this.shake = true; });
                },
            }));
        });
    </script>
</body>
</html>
