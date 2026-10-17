@props(['label', 'value', 'definition' => null, 'tone' => null])

<div {{ $attributes->class('group rounded-xl border border-slate-200 bg-white p-4 transition-all duration-200 hover:-translate-y-0.5 hover:border-slate-300 hover:shadow-md') }}>
    <div class="flex items-center gap-1.5 text-xs font-medium uppercase tracking-wide text-slate-500">
        @if ($tone === 'warning')
            <span class="inline-flex size-4 items-center justify-center rounded-full bg-status-warning text-[10px] font-bold text-white" aria-hidden="true">!</span>
        @elseif ($tone === 'good')
            <span class="inline-flex size-4 items-center justify-center rounded-full bg-status-good text-[10px] font-bold text-white" aria-hidden="true">✓</span>
        @endif
        {{ $label }}
    </div>
    <div class="mt-1 text-2xl font-semibold tabular-nums text-slate-900" x-data="countUp(@js((string) $value))" data-value="{{ $value }}" x-text="display">{{ $value }}</div>
    @if ($definition)
        <p class="mt-1 text-xs leading-snug text-slate-500">{{ $definition }}</p>
    @endif
</div>
