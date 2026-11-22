{{-- Baris batang horizontal: $rows = [label => nilai]; $format = fn (int) => string --}}
@php($max = max(1, ...array_values($rows ?: [0])))
<div class="grid gap-2">
    @forelse ($rows as $label => $value)
        <div class="grid grid-cols-[minmax(0,1fr)_auto] items-center gap-x-3 gap-y-1 text-sm">
            <span class="truncate">{{ $label }}</span>
            <span class="font-medium tabular-nums">{{ $format($value) }}</span>
            <div class="col-span-2 h-2 rounded-full bg-gray-100 dark:bg-white/10">
                <div class="h-2 rounded-full bg-primary-600" style="width: {{ round($value / $max * 100, 1) }}%"></div>
            </div>
        </div>
    @empty
        <p class="text-sm text-gray-500">{{ __('admin.reports.empty') }}</p>
    @endforelse
</div>
