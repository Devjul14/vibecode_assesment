@props(['variant' => 'primary', 'target' => null, 'size' => 'md'])

@php
    $variants = [
        'primary' => 'bg-indigo-600 text-white shadow-sm shadow-indigo-600/20 hover:bg-indigo-700 hover:shadow-md hover:shadow-indigo-600/30',
        'ghost' => 'border border-slate-300 bg-white text-slate-700 hover:border-slate-400 hover:bg-slate-50',
        'danger' => 'bg-rose-600 text-white hover:bg-rose-700',
        'subtle' => 'bg-slate-100 text-slate-700 hover:bg-slate-200',
    ];
    $sizes = ['sm' => 'px-2.5 py-1 text-xs', 'md' => 'px-3 py-1.5 text-sm'];
@endphp

<button {{ $attributes->merge(['type' => 'button'])->class([
    'relative inline-flex items-center gap-1.5 rounded-lg font-medium transition-all duration-150 active:scale-[0.97] disabled:cursor-not-allowed disabled:opacity-60',
    $variants[$variant] ?? $variants['primary'],
    $sizes[$size] ?? $sizes['md'],
]) }} @if ($target) wire:loading.attr="disabled" wire:target="{{ $target }}" @endif>
    @if ($target)
        <svg wire:loading wire:target="{{ $target }}" class="size-3.5 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
            <circle cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3" class="opacity-25" />
            <path d="M22 12a10 10 0 0 1-10 10" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
        </svg>
    @endif
    {{ $slot }}
</button>
