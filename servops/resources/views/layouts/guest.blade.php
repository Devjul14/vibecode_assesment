<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ isset($title) ? $title.' · ' : '' }}{{ config('app.name') }}</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
</head>
<body class="min-h-screen bg-slate-50 text-slate-800 antialiased">
    <div class="pointer-events-none fixed inset-0 overflow-hidden" aria-hidden="true">
        <div class="animate-float absolute -left-24 -top-24 size-96 rounded-full bg-indigo-200/50 blur-3xl"></div>
        <div class="animate-float absolute -bottom-32 -right-24 size-[28rem] rounded-full bg-violet-200/50 blur-3xl" style="animation-delay: -6s"></div>
    </div>
    <main class="relative flex min-h-screen items-center justify-center px-4 py-10">
        {{ $slot }}
    </main>
    <x-ui.js-health />
    @livewireScriptConfig
</body>
</html>
