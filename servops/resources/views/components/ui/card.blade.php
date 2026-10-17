@props(['title' => null, 'subtitle' => null])

<section {{ $attributes->class('animate-enter rounded-xl border border-slate-200 bg-white transition-shadow duration-200 hover:shadow-md') }}>
    @if ($title || isset($actions))
        <header class="flex flex-wrap items-start justify-between gap-2 border-b border-slate-100 px-4 py-3">
            <div>
                <h2 class="text-sm font-semibold text-slate-800">{{ $title }}</h2>
                @if ($subtitle)
                    <p class="mt-0.5 text-xs text-slate-500">{{ $subtitle }}</p>
                @endif
            </div>
            {{ $actions ?? '' }}
        </header>
    @endif
    <div class="p-4">
        {{ $slot }}
    </div>
</section>
