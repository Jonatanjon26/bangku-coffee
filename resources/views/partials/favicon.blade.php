{{-- Ikon aplikasi (tab browser, layar beranda tablet). $svgLink=false bila favicon SVG sudah dipasang (panel Filament). --}}
@if ($svgLink ?? true)
    <link rel="icon" href="{{ asset('favicon.svg') }}" type="image/svg+xml">
@endif
<link rel="icon" href="{{ asset('favicon.ico') }}" sizes="32x32">
<link rel="apple-touch-icon" href="{{ asset('apple-touch-icon.png') }}">
<link rel="manifest" href="{{ route('manifest') }}">
<meta name="theme-color" content="#6f4e37">
