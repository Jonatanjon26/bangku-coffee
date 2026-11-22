@php
    $rp = fn (int $n) => 'Rp '.number_format($n, 0, ',', '.');
    $short = fn (int $n) => $n >= 1000000 ? rtrim(rtrim(number_format($n / 1000000, 1, ',', '.'), '0'), ',').' jt' : ($n >= 1000 ? number_format($n / 1000, 0, ',', '.').' rb' : (string) $n);
@endphp
<div class="grid gap-6">
    <div class="flex flex-wrap items-center gap-2" id="report-range">
        @foreach (\App\Filament\Pages\Reports::RANGES as $r)
            <x-filament::button :color="$range === $r ? 'primary' : 'gray'" size="sm" wire:click="setRange('{{ $r }}')" :data-range="$r">{{ __('admin.reports.range_'.$r) }}</x-filament::button>
        @endforeach
        @if ($range === 'month')
            <x-filament::input.wrapper class="w-48">
                <x-filament::input.select id="report-month" wire:model.live="month" :aria-label="__('admin.reports.month')">
                    @foreach ($months as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </x-filament::input.select>
            </x-filament::input.wrapper>
        @endif
        <span class="text-sm text-gray-500">{{ $from->translatedFormat('j M Y') }}@unless ($from->equalTo($to)) – {{ $to->translatedFormat('j M Y') }}@endunless</span>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4" id="report-stats">
        <x-filament::section compact><p class="text-sm text-gray-500">{{ __('admin.reports.revenue') }}</p><p class="text-2xl font-semibold tabular-nums">{{ $rp($totals['total']) }}</p></x-filament::section>
        <x-filament::section compact><p class="text-sm text-gray-500">{{ __('admin.dashboard.transactions') }}</p><p class="text-2xl font-semibold tabular-nums">{{ $totals['count'] }}</p><p class="text-xs text-gray-500">{{ __('admin.dashboard.average', ['amount' => $rp($totals['average'])]) }}</p></x-filament::section>
        <x-filament::section compact><p class="text-sm text-gray-500">{{ __('admin.reports.discount') }}</p><p class="text-2xl font-semibold tabular-nums">{{ $rp($totals['discount']) }}</p></x-filament::section>
        <x-filament::section compact><p class="text-sm text-gray-500">{{ __('admin.reports.refunds') }}</p><p class="text-2xl font-semibold tabular-nums">{{ $rp($totals['refunds']) }}</p><p class="text-xs text-gray-500">{{ __('admin.reports.refund_count', ['count' => $totals['refund_count']]) }}</p></x-filament::section>
    </div>

    @if ($totals['count'])
        @if ($daily)
            <x-filament::section :heading="__('admin.reports.daily')">
                @include('filament.partials.bar-chart', ['bars' => array_map(fn ($r) => [count($daily) > 10 ? \Carbon\CarbonImmutable::parse($r['date'])->format('j') : $r['label'], $r['value']], $daily), 'format' => $short])
            </x-filament::section>
        @endif
        <x-filament::section :heading="__('admin.reports.hours')" :description="__('admin.reports.hours_hint')">
            @include('filament.partials.bar-chart', ['bars' => collect($hourly)->map(fn ($v, $h) => [$h, $v])->values()->all(), 'format' => $short])
        </x-filament::section>
        <div class="grid gap-6 lg:grid-cols-2">
            <x-filament::section :heading="__('admin.reports.by_category')">@include('filament.partials.bar-rows', ['rows' => $categories, 'format' => $rp])</x-filament::section>
            <x-filament::section :heading="__('admin.reports.by_method')">@include('filament.partials.bar-rows', ['rows' => $methods, 'format' => $rp])</x-filament::section>
            <x-filament::section :heading="__('admin.reports.by_cashier')">@include('filament.partials.bar-rows', ['rows' => $cashiers, 'format' => $rp])</x-filament::section>
            <x-filament::section :heading="__('admin.reports.by_type')">@include('filament.partials.bar-rows', ['rows' => $types, 'format' => $rp])</x-filament::section>
        </div>
        <x-filament::section :heading="__('admin.reports.items')" id="report-items">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead><tr class="border-b border-gray-200 text-left text-gray-500 dark:border-white/10"><th class="py-2">{{ __('admin.reports.item') }}</th><th class="py-2">{{ __('admin.reports.category') }}</th><th class="py-2 text-right">{{ __('admin.reports.qty') }}</th><th class="py-2 text-right">{{ __('admin.reports.revenue') }}</th></tr></thead>
                    <tbody>
                        @foreach ($items as $row)
                            <tr class="border-b border-gray-100 dark:border-white/5"><td class="py-2">{{ $row['name'] }}</td><td class="py-2 text-gray-500">{{ $row['category'] }}</td><td class="py-2 text-right tabular-nums">{{ $row['qty'] }}</td><td class="py-2 text-right tabular-nums">{{ $rp($row['total']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-filament::section>
    @else
        <x-filament::section><p class="text-sm text-gray-500">{{ __('admin.reports.empty_period') }}</p></x-filament::section>
    @endif
</div>
