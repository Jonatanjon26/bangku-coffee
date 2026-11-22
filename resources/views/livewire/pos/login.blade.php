@php
    $storeName = app(\App\Support\Settings::class)->get('store.name');
    // Warna avatar (sama dengan mockup) dipilih tetap per karyawan.
    $colors = ['#6f4e37', '#0f766e', '#7c3aed', '#c2410c', '#1d4ed8', '#be185d', '#4d7c0f'];
    $avatar = fn ($user) => $colors[$user->id % count($colors)];
    $initials = fn (string $name) => mb_strtoupper(mb_substr(collect(preg_split('/\s+/', trim($name)))->map(fn ($w) => mb_substr($w, 0, 1))->implode(''), 0, 2));
    $keys = ['1', '2', '3', '4', '5', '6', '7', '8', '9'];
@endphp
<div class="login">
    <aside class="brand-side">
        <div class="logo">
            <span class="logo-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 8h1a4 4 0 1 1 0 8h-1"/><path d="M3 8h14v9a4 4 0 0 1-4 4H7a4 4 0 0 1-4-4Z"/><line x1="6" x2="6" y1="2" y2="4"/><line x1="10" x2="10" y1="2" y2="4"/><line x1="14" x2="14" y1="2" y2="4"/></svg></span>
            <span><span>{{ $storeName }}</span><small>{{ __('pos.login.app') }}</small></span>
        </div>
        <div x-data="{ now: new Date(), locale: @js(str_replace('_', '-', app()->getLocale())) }" x-init="setInterval(() => now = new Date(), 10000)">
            <div class="clock" x-text="now.toLocaleTimeString(locale, { hour: '2-digit', minute: '2-digit' })">--:--</div>
            <div class="date" x-text="now.toLocaleDateString(locale, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })"></div>
        </div>
        <div class="status">
            <div class="status-row"><span @class(['dot', 'on' => $device])></span><span>{{ $device?->name ?? __('pos.login.device_unregistered') }}</span></div>
            <div class="status-row"><span @class(['dot', 'on' => $shift])></span><span>{{ $shift ? __('pos.login.shift_open', ['name' => $shift->name, 'cashier' => $shift->opener?->name, 'time' => $shift->opened_at->format('H:i')]) : __('pos.login.no_shift') }}</span></div>
            <a class="admin-link" href="{{ route('filament.admin.auth.login') }}">{{ __('pos.login.admin_link') }}</a>
        </div>
    </aside>

    <main class="main-side">
        <div class="top-actions">
            <button class="icon-btn" type="button" aria-label="{{ __('pos.login.theme') }}" x-on:click="bangkuToggleTheme()"><svg class="i" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a6 6 0 0 0 9 9 9 9 0 1 1-9-9Z"/></svg></button>
        </div>

        @if ($step === 'pair')
            {{-- 1. Daftarkan tablet (sekali per tablet) --}}
            <section class="panel" id="step-pair" wire:key="pair">
                <div><h1>{{ __('pos.login.pair_title') }}</h1><p class="sub">{{ __('pos.login.pair_sub') }}</p></div>
                <div class="pin-card" x-data="keypad(6, 'pair')" x-on:keypad-failed.window="failed()">
                    <output class="pair-code" id="pair-code" x-text="value.padEnd(6, '–')">––––––</output>
                    <p @class(['pin-msg', 'ok' => ! $error]) id="pair-msg">{{ $error ?: __('pos.login.pair_hint') }}</p>
                    <div class="keypad" id="pair-keypad">
                        @foreach ($keys as $k)<button type="button" data-k="{{ $k }}" x-on:click="press('{{ $k }}')">{{ $k }}</button>@endforeach
                        <span></span>
                        <button type="button" data-k="0" x-on:click="press('0')">0</button>
                        <button type="button" data-k="back" class="ghost" aria-label="{{ __('pos.login.backspace') }}" x-on:click="press('back')">⌫</button>
                    </div>
                </div>
            </section>
        @elseif ($step === 'people')
            {{-- 2. Pilih kasir --}}
            <section class="panel" id="step-people" wire:key="people">
                @if ($locked)<p class="notice" id="locked-notice">{{ __('pos.login.locked_notice') }}</p>@endif
                <div><h1>{{ __('pos.login.people_title') }}</h1><p class="sub">{{ __('pos.login.people_sub') }}</p></div>
                <div class="people" id="people">
                    @forelse ($cashiers as $person)
                        <button type="button" class="person" data-staff="{{ $person->id }}" wire:click="select({{ $person->id }})">
                            <span class="avatar" style="background: {{ $avatar($person) }}">{{ $initials($person->name) }}</span>
                            <b>{{ $person->name }}</b>
                            <span>{{ $person->role?->name }}</span>
                        </button>
                    @empty
                        <p class="empty">{{ __('pos.login.people_empty') }}</p>
                    @endforelse
                </div>
            </section>
        @elseif ($selected)
            {{-- 3. PIN (salah berulang → dikunci sementara) --}}
            <section class="panel" id="step-pin" wire:key="pin-{{ $selected->id }}">
                <div class="pin-card" x-data="keypad({{ $pinLength }}, 'login')" x-on:keypad-failed.window="failed()">
                    <span class="avatar" style="background: {{ $avatar($selected) }}">{{ $initials($selected->name) }}</span>
                    <div style="text-align: center"><h1 id="pin-name">{{ $selected->name }}</h1><p class="sub">{{ __('pos.login.pin_sub', ['role' => $selected->role?->name, 'length' => $pinLength]) }}</p></div>
                    <div class="dots" id="dots" aria-live="polite" x-bind:class="{ shake }">
                        @for ($i = 0; $i < $pinLength; $i++)<i x-bind:class="{ on: value.length > {{ $i }} }"></i>@endfor
                    </div>
                    <div wire:key="lock-{{ $lockedSeconds }}-{{ $error }}" x-data="{ left: {{ $lockedSeconds }} }"
                         x-init="if (left > 0) { const t = setInterval(() => { left--; if (left <= 0) clearInterval(t); }, 1000); }">
                        <p class="pin-msg" id="pin-msg" x-show="left > 0" x-text="@js(__('pos.login.errors.locked', ['seconds' => '__S__'])).replace('__S__', left)"></p>
                        <p class="pin-msg" x-show="left <= 0">{{ $error }}</p>
                        <div class="keypad" id="pin-keypad" x-bind:aria-disabled="left > 0">
                            @foreach ($keys as $k)<button type="button" data-k="{{ $k }}" x-bind:disabled="left > 0" x-on:click="press('{{ $k }}')">{{ $k }}</button>@endforeach
                            <button type="button" data-k="cancel" class="ghost" wire:click="back">{{ __('pos.login.cancel') }}</button>
                            <button type="button" data-k="0" x-bind:disabled="left > 0" x-on:click="press('0')">0</button>
                            <button type="button" data-k="back" class="ghost" aria-label="{{ __('pos.login.backspace') }}" x-on:click="press('back')">⌫</button>
                        </div>
                    </div>
                </div>
            </section>
        @endif
    </main>
</div>
