@props(['status'])

@php
    $classes = [
        'slate' => 'bg-slate-100 text-slate-700',
        'amber' => 'bg-amber-50 text-amber-800 ring-amber-200',
        'sky' => 'bg-sky-50 text-sky-800',
        'indigo' => 'bg-indigo-50 text-indigo-800',
        'rose' => 'bg-rose-50 text-rose-800',
        'violet' => 'bg-violet-50 text-violet-800',
        'orange' => 'bg-orange-50 text-orange-800',
        'emerald' => 'bg-emerald-50 text-emerald-800',
        'zinc' => 'bg-zinc-100 text-zinc-600',
    ][$status->color()] ?? 'bg-slate-100 text-slate-700';
@endphp

<span {{ $attributes->class("inline-flex items-center whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-medium {$classes}") }}>
    {{ $status->label() }}
</span>
