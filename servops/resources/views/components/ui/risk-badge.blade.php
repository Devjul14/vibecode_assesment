@props(['level'])

@if ($level)
    @php
        [$dot, $icon] = match ($level) {
            \App\Enums\RiskLevel::OnTrack => ['bg-status-good', '✓'],
            \App\Enums\RiskLevel::AtRisk => ['bg-status-warning', '!'],
            \App\Enums\RiskLevel::WillBreach => ['bg-status-serious', '▲'],
            \App\Enums\RiskLevel::Breached => ['bg-status-critical', '✕'],
        };
    @endphp
    <span {{ $attributes->class('inline-flex items-center gap-1.5 whitespace-nowrap rounded-full border border-slate-200 bg-white px-2 py-0.5 text-xs font-medium text-slate-700') }}>
        <span @class([
            'inline-flex size-4 items-center justify-center rounded-full text-[10px] font-bold text-white',
            $dot,
            'animate-pulse-ring text-status-serious' => $level === \App\Enums\RiskLevel::WillBreach,
            'animate-pulse-ring text-status-critical' => $level === \App\Enums\RiskLevel::Breached,
        ]) aria-hidden="true"><span class="text-white">{{ $icon }}</span></span>
        {{ $level->label() }}
    </span>
@else
    <span class="text-xs text-slate-400">–</span>
@endif
