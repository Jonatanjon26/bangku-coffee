@php
    $rp = fn (int $n) => 'Rp '.number_format($n, 0, ',', '.');
    $short = fn (int $n) => $n >= 1000000 ? rtrim(rtrim(number_format($n / 1000000, 1, ',', '.'), '0'), ',').' jt' : ($n >= 1000 ? number_format($n / 1000, 0, ',', '.').' rb' : (string) $n);
@endphp
<div class="grid gap-6">
    @livewire(\Filament\Widgets\AccountWidget::class)

    @if ($canReport)
        @if ($d['edc_unsettled'])
            <x-filament::section compact id="dashboard-edc-warning" icon="heroicon-o-exclamation-triangle" icon-color="warning"
                :heading="__('admin.dashboard.edc_unsettled', ['dates' => collect($d['edc_unsettled'])->map(fn ($day) => $day->translatedFormat('j M'))->implode(', ')])">
                <p class="text-sm text-gray-500">{{ __('admin.dashboard.edc_unsettled_hint') }}</p>
            </x-filament::section>
        @endif
        @php($t = $d['today'])
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" id="dashboard-stats">
            <x-filament::section compact>
                <p class="text-sm text-gray-500">{{ __('admin.dashboard.sales_today') }}</p>
                <p class="text-2xl font-semibold tabular-nums">{{ $rp($t['total']) }}</p>
                <p class="text-xs text-gray-500">{{ $d['yesterday'] ? __('admin.dashboard.vs_yesterday', ['pct' => number_format(($t['total'] - $d['yesterday']) / $d['yesterday'] * 100, 1, ',', '.')]) : __('admin.dashboard.no_yesterday') }}</p>
            </x-filament::section>
            <x-filament::section compact>
                <p class="text-sm text-gray-500">{{ __('admin.dashboard.transactions') }}</p>
                <p class="text-2xl font-semibold tabular-nums">{{ $t['count'] }}</p>
                <p class="text-xs text-gray-500">{{ __('admin.dashboard.average', ['amount' => $rp($t['average'])]) }}</p>
            </x-filament::section>
            <x-filament::section compact>
                <p class="text-sm text-gray-500">{{ __('admin.dashboard.customers') }}</p>
                <p class="text-2xl font-semibold tabular-nums">{{ $d['customers'] }}</p>
                <p class="text-xs text-gray-500">{{ __('admin.dashboard.member_orders', ['count' => $d['member_orders']]) }}</p>
            </x-filament::section>
            <x-filament::section compact>
                <p class="text-sm text-gray-500">{{ __('admin.dashboard.variance') }}</p>
                <p @class(['text-2xl font-semibold tabular-nums', 'text-danger-600' => $d['variance'] < 0])>{{ ($d['variance'] < 0 ? '−' : '').$rp(abs($d['variance'])) }}</p>
                <p class="text-xs text-gray-500">{{ __('admin.dashboard.closed_shifts', ['count' => $d['closed_shifts']->count()]) }}</p>
            </x-filament::section>
        </div>

        <div class="grid gap-6 lg:grid-cols-2">
            <x-filament::section :heading="__('admin.dashboard.week')" :description="__('admin.dashboard.week_hint')">
                @include('filament.partials.bar-chart', ['bars' => array_map(fn ($r) => [$r['label'], $r['value']], $d['week']), 'format' => $short])
            </x-filament::section>
            <x-filament::section :heading="__('admin.dashboard.methods')" :description="__('admin.dashboard.methods_hint')">
                @include('filament.partials.bar-rows', ['rows' => $d['methods'], 'format' => $rp])
            </x-filament::section>
            <x-filament::section :heading="__('admin.dashboard.top')">
                @include('filament.partials.bar-rows', ['rows' => collect($d['top'])->pluck('qty', 'name')->all(), 'format' => fn ($n) => $n.'×'])
            </x-filament::section>
            <x-filament::section :heading="__('admin.dashboard.shifts')">
                <div class="grid gap-2 text-sm" id="dashboard-shifts">
                    @forelse ($d['running_shifts'] as $s)
                        <div class="flex justify-between gap-3"><span>{{ __('admin.dashboard.shift_line', ['name' => $s->name, 'cashier' => $s->opener?->name]) }} <x-filament::badge color="success" class="inline-flex">{{ __('admin.shifts.running') }}</x-filament::badge></span><span class="tabular-nums">{{ $rp($s->summarize()['net']) }}</span></div>
                    @empty
                        <div class="text-gray-500">{{ __('admin.dashboard.no_running') }}</div>
                    @endforelse
                    @if ($d['open_bills']['count'])
                        <div class="flex justify-between gap-3"><a class="text-primary-600 underline" href="{{ \App\Filament\Resources\Orders\OrderResource::getUrl('index', ['filters' => ['status' => ['value' => 'open']]]) }}">{{ __('admin.dashboard.open_bills', ['count' => $d['open_bills']['count']]) }}</a>
                            @if ($d['open_bills']['overdue'])<x-filament::badge color="danger" class="inline-flex">{{ __('admin.dashboard.overdue') }}</x-filament::badge>@endif
                            <span class="tabular-nums">{{ $rp($d['open_bills']['total']) }}</span></div>
                    @endif
                    @foreach ($d['closed_shifts'] as $s)
                        <div class="flex justify-between gap-3"><span>{{ __('admin.dashboard.shift_line', ['name' => $s->name, 'cashier' => $s->opener?->name]) }}
                            @if ($s->variance)<x-filament::badge color="danger" class="inline-flex">{{ __('admin.shifts.variance_badge', ['amount' => ($s->variance < 0 ? '−' : '+').number_format(abs($s->variance), 0, ',', '.')]) }}</x-filament::badge>
                            @else<x-filament::badge color="success" class="inline-flex">{{ __('admin.shifts.match') }}</x-filament::badge>@endif</span>
                            <span class="tabular-nums">{{ $rp((int) ($s->summary['net'] ?? $s->summary['total'] ?? 0)) }}</span></div>
                    @endforeach
                </div>
            </x-filament::section>
        </div>
    @endif
</div>
