@props(['value', 'max' => 1, 'label' => null, 'caption' => null, 'thresholds' => [0.8, 1.0]])

@php
    $ratio = $max > 0 ? $value / $max : 0;
    $width = min(100, max(0, $ratio * 100));
    [$fill, $icon, $state] = match (true) {
        $ratio > $thresholds[1] => ['bg-status-critical', '✕', 'Melebihi kapasitas'],
        $ratio > $thresholds[0] => ['bg-status-warning', '!', 'Mendekati batas'],
        default => ['bg-series-1', null, null],
    };
@endphp

<div {{ $attributes->class('group relative') }} title="{{ $label }}: {{ round($ratio * 100) }}%{{ $caption ? ' — '.$caption : '' }}">
    @if ($label)
        <div class="mb-1 flex items-baseline justify-between gap-2 text-sm">
            <span class="font-medium text-slate-700">{{ $label }}</span>
            <span class="flex items-center gap-1 tabular-nums text-slate-600">
                @if ($icon)
                    <span class="inline-flex size-4 items-center justify-center rounded-full {{ $fill }} text-[10px] font-bold text-white" aria-label="{{ $state }}">{{ $icon }}</span>
                @endif
                {{ round($ratio * 100) }}%
            </span>
        </div>
    @endif
    <div class="h-2 w-full overflow-hidden rounded-full bg-series-1-soft/60" role="meter" aria-valuenow="{{ round($ratio * 100) }}" aria-valuemin="0" aria-valuemax="100">
        <div class="h-full rounded-full {{ $fill }}" style="width: 0" x-data x-grow="{{ round($width, 1) }}"></div>
    </div>
    @if ($caption)
        <p class="mt-1 text-xs text-slate-500">{{ $caption }}{{ $state ? ' · '.$state : '' }}</p>
    @endif
</div>
