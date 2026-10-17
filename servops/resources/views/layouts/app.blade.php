<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
@php
    $user = auth()->user();
    $clock = app(\App\Services\Calendar\Clock::class);
    $openRecommendations = $user?->can('recommendations.view') ? \App\Models\Recommendation::query()->open()->count() : 0;
    $navItems = [
        ['label' => 'Dashboard', 'route' => 'dashboard', 'match' => 'dashboard', 'can' => 'dashboard.view',
            'icon' => 'M3 13h8V3H3v10Zm0 8h8v-6H3v6Zm10 0h8V11h-8v10Zm0-18v6h8V3h-8Z'],
        ['label' => 'Requests', 'route' => 'requests.index', 'match' => 'requests.*', 'can' => null,
            'icon' => 'M4 4h16v12H15l-3 3-3-3H4V4Zm4 5h8M8 12h5'],
        ['label' => 'Tim & Kapasitas', 'route' => 'team.index', 'match' => 'team.*', 'can' => 'team.view',
            'icon' => 'M16 19v-1a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v1M9 10a3 3 0 1 0 0-6 3 3 0 0 0 0 6Zm13 9v-1a4 4 0 0 0-3-3.87M16 4.13a3 3 0 0 1 0 5.74'],
        ['label' => 'Risiko SLA', 'route' => 'risk.index', 'match' => 'risk.*', 'can' => 'risk.view',
            'icon' => 'M12 9v4m0 4h.01M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0Z'],
        ['label' => 'Rekomendasi', 'route' => 'recommendations.index', 'match' => 'recommendations.*', 'can' => 'recommendations.view',
            'icon' => 'M9 18h6m-5 3h4M12 3a6 6 0 0 0-3.6 10.8c.6.5 1 1.2 1 2V16h5.2v-.2c0-.8.4-1.5 1-2A6 6 0 0 0 12 3Z', 'badge' => $openRecommendations],
        ['label' => 'Simulasi', 'route' => 'simulation.index', 'match' => 'simulation.*', 'can' => 'simulation.run',
            'icon' => 'M5 3l14 9-14 9V3Z'],
    ];
@endphp
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased" x-data="{ sidebarOpen: false }" @keydown.escape.window="sidebarOpen = false">
    {{-- Overlay mobile --}}
    <div x-show="sidebarOpen" x-transition.opacity @click="sidebarOpen = false" class="fixed inset-0 z-30 bg-slate-900/40 backdrop-blur-sm lg:hidden" x-cloak></div>

    <aside class="fixed inset-y-0 left-0 z-40 flex w-64 -translate-x-full flex-col border-r border-slate-200 bg-white transition-transform duration-300 ease-out lg:translate-x-0"
        :class="sidebarOpen && 'translate-x-0'">
        <div class="flex h-16 items-center gap-3 px-5">
            <div class="flex size-9 items-center justify-center rounded-xl bg-gradient-to-br from-indigo-500 to-violet-600 font-bold text-white shadow-md shadow-indigo-500/30">S</div>
            <div>
                <p class="text-sm font-semibold text-slate-900">ServOps</p>
                <p class="text-[11px] text-slate-500">Capacity & SLA planning</p>
            </div>
        </div>

        @can('create', \App\Models\ServiceRequest::class)
            <div class="px-4 pb-2">
                <a href="{{ route('requests.create') }}" wire:navigate class="flex items-center justify-center gap-1.5 rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white shadow-sm shadow-indigo-600/20 transition hover:-translate-y-0.5 hover:bg-indigo-700 hover:shadow-md">
                    <span class="text-base leading-none">+</span> Request baru
                </a>
            </div>
        @endcan

        <nav class="flex-1 space-y-0.5 overflow-y-auto px-3 py-2">
            @foreach ($navItems as $item)
                @continue($item['can'] && ! $user?->can($item['can']))
                @php $active = request()->routeIs($item['match']); @endphp
                @if (Route::has($item['route']))
                    <a href="{{ route($item['route']) }}" wire:navigate @click="sidebarOpen = false"
                        @class([
                            'group relative flex items-center gap-3 rounded-lg px-3 py-2 text-sm transition-all duration-150',
                            'bg-indigo-50 font-medium text-indigo-700' => $active,
                            'text-slate-600 hover:translate-x-0.5 hover:bg-slate-100 hover:text-slate-900' => ! $active,
                        ])>
                        @if ($active)
                            <span class="absolute inset-y-1.5 left-0 w-1 rounded-r-full bg-indigo-600"></span>
                        @endif
                        <svg class="size-5 shrink-0 {{ $active ? 'text-indigo-600' : 'text-slate-400 group-hover:text-slate-600' }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $item['icon'] }}" /></svg>
                        <span class="flex-1">{{ $item['label'] }}</span>
                        @if (($item['badge'] ?? 0) > 0)
                            <span class="rounded-full bg-indigo-600 px-1.5 py-0.5 text-[11px] font-semibold tabular-nums text-white">{{ $item['badge'] }}</span>
                        @endif
                    </a>
                @else
                    <span class="flex cursor-not-allowed items-center gap-3 rounded-lg px-3 py-2 text-sm text-slate-400" title="Segera hadir">
                        <svg class="size-5 shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="{{ $item['icon'] }}" /></svg>
                        <span class="flex-1">{{ $item['label'] }}</span>
                        <span class="rounded bg-slate-100 px-1.5 text-[10px]">segera</span>
                    </span>
                @endif
            @endforeach
        </nav>

        @if ($user)
            <div class="border-t border-slate-200 p-3" x-data="{ open: false }">
                <button type="button" @click="open = !open" class="flex w-full items-center gap-3 rounded-lg p-2 text-left transition hover:bg-slate-100">
                    <span class="flex size-9 shrink-0 items-center justify-center rounded-full bg-gradient-to-br from-slate-700 to-slate-900 text-xs font-semibold text-white">{{ $user->initials() }}</span>
                    <span class="min-w-0 flex-1">
                        <span class="block truncate text-sm font-medium text-slate-800">{{ $user->name }}</span>
                        <span class="block truncate text-xs text-slate-500">{{ $user->role->label() }}{{ $user->department ? ' · '.$user->department->name : '' }}</span>
                    </span>
                    <svg class="size-4 text-slate-400 transition-transform" :class="open && 'rotate-180'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m6 15 6-6 6 6" /></svg>
                </button>
                <div x-show="open" x-collapse x-cloak>
                    <div class="mt-2 rounded-lg bg-slate-50 p-2 text-xs text-slate-600">
                        <p class="mb-1 font-medium text-slate-700">Hak akses</p>
                        <ul class="space-y-0.5">
                            @foreach ($user->role->permissions() as $permission)
                                <li>✓ {{ $permission->label() }}</li>
                            @endforeach
                        </ul>
                    </div>
                    <form method="POST" action="{{ route('logout') }}" class="mt-2">
                        @csrf
                        <button type="submit" class="w-full rounded-lg px-3 py-2 text-left text-sm text-rose-700 transition hover:bg-rose-50">Keluar</button>
                    </form>
                </div>
            </div>
        @endif
    </aside>

    <div class="lg:pl-64">
        <header class="sticky top-0 z-20 flex h-16 items-center gap-3 border-b border-slate-200 bg-white/80 px-4 backdrop-blur-md sm:px-6">
            <button type="button" @click="sidebarOpen = true" class="rounded-lg p-2 text-slate-600 hover:bg-slate-100 lg:hidden" aria-label="Buka menu">
                <svg class="size-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><path d="M4 6h16M4 12h16M4 18h16" /></svg>
            </button>
            <h2 class="min-w-0 flex-1 truncate text-sm font-medium text-slate-500">{{ $title ?? '' }}</h2>
            <span @class([
                'hidden items-center gap-1.5 rounded-full px-3 py-1 text-xs font-medium tabular-nums sm:inline-flex',
                'bg-amber-50 text-amber-800 ring-1 ring-amber-200' => $clock->isSimulated(),
                'bg-slate-100 text-slate-600' => ! $clock->isSimulated(),
            ]) title="{{ $clock->isSimulated() ? 'Waktu simulasi' : 'Waktu nyata' }}">
                <span class="relative flex size-2">
                    <span class="absolute inline-flex size-full animate-ping rounded-full {{ $clock->isSimulated() ? 'bg-amber-400' : 'bg-emerald-400' }} opacity-60"></span>
                    <span class="relative inline-flex size-2 rounded-full {{ $clock->isSimulated() ? 'bg-amber-500' : 'bg-emerald-500' }}"></span>
                </span>
                {{ $clock->now()->translatedFormat('D, d M Y H:i') }}{{ $clock->isSimulated() ? ' · simulasi' : '' }}
            </span>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6">
            {{ $slot ?? '' }}
        </main>
    </div>

    {{-- Toast --}}
    <div x-data="toaster(@js(session('notice')))" @toast.window="push($event.detail)"
        class="pointer-events-none fixed bottom-4 right-4 z-50 flex w-full max-w-sm flex-col gap-2" aria-live="polite">
        <template x-for="toast in toasts" :key="toast.id">
            <div x-show="toast.visible" x-transition:enter="transition duration-300 ease-out" x-transition:enter-start="translate-y-3 opacity-0 scale-95"
                x-transition:leave="transition duration-200 ease-in" x-transition:leave-end="translate-x-6 opacity-0"
                class="pointer-events-auto overflow-hidden rounded-xl border bg-white shadow-lg shadow-slate-900/10"
                :class="toast.type === 'error' ? 'border-rose-200' : 'border-emerald-200'">
                <div class="flex items-start gap-3 p-3">
                    <span class="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full text-[11px] font-bold text-white"
                        :class="toast.type === 'error' ? 'bg-status-critical' : 'bg-status-good'" x-text="toast.type === 'error' ? '✕' : '✓'"></span>
                    <div class="min-w-0 flex-1 text-sm">
                        <p class="font-medium text-slate-800" x-text="toast.message"></p>
                        <template x-for="warning in (toast.warnings || [])">
                            <p class="mt-1 text-xs text-amber-700" x-text="'⚠ ' + warning"></p>
                        </template>
                    </div>
                    <button type="button" @click="dismiss(toast.id)" class="text-slate-400 hover:text-slate-600" aria-label="Tutup">✕</button>
                </div>
                <div class="h-0.5 origin-left" :class="toast.type === 'error' ? 'bg-rose-300' : 'bg-emerald-300'"
                    x-init="$el.animate([{ transform: 'scaleX(1)' }, { transform: 'scaleX(0)' }], { duration: toast.type === 'error' ? 8000 : 4500, easing: 'linear', fill: 'forwards' })"></div>
            </div>
        </template>
    </div>

    <x-ui.js-health />
    @livewireScriptConfig
</body>
</html>
