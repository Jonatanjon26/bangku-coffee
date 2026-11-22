{{-- Batang vertikal: $bars = [[label, nilai]]; $format = fn (int) => string --}}
@php($max = max(1, ...array_map(fn ($b) => $b[1], $bars ?: [['', 0]])))
<div class="flex h-48 items-end gap-2">
    @foreach ($bars as [$label, $value])
        <div class="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1" title="{{ $label }} · {{ $format($value) }}">
            <span class="text-[10px] tabular-nums text-gray-500">{{ $value ? $format($value) : '' }}</span>
            <div class="w-full rounded-t-md bg-primary-600" style="height: {{ max($value ? 2 : 0, round($value / $max * 100, 1)) }}%"></div>
            <span class="text-xs text-gray-500">{{ $label }}</span>
        </div>
    @endforeach
</div>
