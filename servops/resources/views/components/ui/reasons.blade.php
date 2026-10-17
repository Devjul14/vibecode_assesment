@props(['reasons' => [], 'empty' => 'Tidak ada catatan.'])

@if (empty($reasons))
    <p class="text-sm text-slate-500">{{ $empty }}</p>
@else
    <ul {{ $attributes->class('space-y-2') }}>
        @foreach ($reasons as $reason)
            <li class="rounded-md border border-slate-100 bg-slate-50 px-3 py-2 text-sm">
                <div class="flex flex-wrap items-center gap-2">
                    <span class="rounded bg-white px-1.5 py-0.5 font-mono text-[11px] text-slate-500 ring-1 ring-slate-200">{{ $reason['code'] }}</span>
                    <span class="text-slate-800">{{ $reason['message'] }}</span>
                </div>
                @if (! empty($reason['action']))
                    <p class="mt-1 text-xs text-indigo-700">→ {{ $reason['action'] }}</p>
                @endif
            </li>
        @endforeach
    </ul>
@endif
