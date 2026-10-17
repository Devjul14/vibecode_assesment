@php
    $input = 'mt-1 w-full rounded-lg border border-slate-300 px-2 py-1.5 text-sm transition focus:border-indigo-500 focus:outline-none focus:ring-4 focus:ring-indigo-100';
    $label = 'block text-xs font-medium text-slate-600';
    $fmt = fn (?string $value) => $value ? \Carbon\CarbonImmutable::parse($value)->translatedFormat('D d M H:i') : '–';
@endphp

<div class="space-y-6">
    <div class="animate-enter flex flex-wrap items-end justify-between gap-4">
        <div>
            <h1 class="text-2xl font-semibold">Simulasi skenario</h1>
            <p class="max-w-2xl text-sm text-slate-500">Uji bagaimana sistem bereaksi terhadap perubahan. <strong>Preview</strong> menjalankan semua engine lalu membatalkannya (tanpa efek samping); <strong>Jalankan</strong> menerapkan perubahan sungguhan lewat workflow yang sama — guard, audit log, dan rekomendasi tetap berlaku.</p>
        </div>
    </div>

    {{-- Kontrol jam --}}
    <div class="animate-enter flex flex-wrap items-center gap-4 rounded-2xl border border-amber-200 bg-gradient-to-r from-amber-50 to-white p-4" style="--i: 1">
        <div class="flex items-center gap-3">
            <div class="flex size-12 items-center justify-center rounded-xl bg-white text-2xl shadow-sm ring-1 ring-amber-200">🕘</div>
            <div>
                <p class="text-xs font-medium uppercase tracking-wide text-amber-700">{{ $simulated ? 'Waktu simulasi' : 'Waktu nyata' }}</p>
                <p class="text-lg font-semibold tabular-nums text-slate-900" wire:key="clock-{{ $now->timestamp }}" x-data x-init="$el.animate([{ opacity: 0, transform: 'translateY(-6px)' }, { opacity: 1, transform: 'none' }], { duration: 350, easing: 'ease-out' })">{{ $now->translatedFormat('l, d M Y · H:i') }}</p>
            </div>
        </div>
        <div class="flex flex-1 flex-wrap justify-end gap-2">
            @foreach ([1 => '+1 jam', 4 => '+4 jam', 8 => '+1 hari kerja', 24 => '+3 hari kerja'] as $hours => $text)
                <x-ui.button variant="ghost" wire:click="advance({{ $hours }})" target="advance({{ $hours }})">{{ $text }}</x-ui.button>
            @endforeach
            @if ($simulated)
                <x-ui.button variant="subtle" wire:click="release" target="release" wire:confirm="Kembali ke waktu nyata? Data seed dibuat relatif terhadap waktu simulasi, sehingga angka risiko bisa berubah drastis.">Waktu nyata</x-ui.button>
            @endif
        </div>
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        {{-- Katalog skenario --}}
        <div class="grid gap-4 sm:grid-cols-2 xl:col-span-2">
            @foreach ($catalog as $scenario)
                @php $locked = $scenario->requiresManager() && ! $isManager; @endphp
                <div wire:key="scenario-{{ $scenario->value }}" style="--i: {{ $loop->index + 2 }}"
                    @class([
                        'animate-enter group flex flex-col rounded-2xl border bg-white p-4 transition-all duration-200 hover:-translate-y-1 hover:shadow-lg',
                        'border-indigo-300 ring-2 ring-indigo-100' => ($result['scenario'] ?? null) === $scenario,
                        'border-slate-200' => ($result['scenario'] ?? null) !== $scenario,
                    ])>
                    <div class="flex items-start gap-3">
                        <span class="flex size-9 shrink-0 items-center justify-center rounded-xl bg-indigo-50 text-sm font-bold text-indigo-700 transition group-hover:bg-indigo-600 group-hover:text-white">{{ $scenario->code() }}</span>
                        <div class="min-w-0">
                            <h3 class="font-semibold leading-snug text-slate-800">{{ $scenario->label() }}</h3>
                            <p class="mt-0.5 text-sm text-slate-600">{{ $scenario->description() }}</p>
                        </div>
                    </div>

                    <div x-data="{ open: false }" class="mt-2">
                        <button type="button" @click="open = !open" class="text-xs font-medium text-indigo-700">
                            <span x-text="open ? '▾' : '▸'"></span> Yang seharusnya terlihat
                        </button>
                        <p x-show="open" x-collapse x-cloak class="mt-1 rounded-lg bg-indigo-50/60 p-2 text-xs text-indigo-900">{{ $scenario->expectation() }}</p>
                    </div>

                    <div class="mt-3 flex-1 space-y-2">
                        @switch($scenario)
                            @case(\App\Enums\Scenario::MemberUnavailable)
                                <div class="grid grid-cols-3 gap-2">
                                    <div class="col-span-2">
                                        <label class="{{ $label }}">Anggota</label>
                                        <select wire:model="memberId" class="{{ $input }}">
                                            @foreach ($members as $member)
                                                <option value="{{ $member->id }}">{{ $member->name }} — {{ $member->role_title }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="{{ $label }}">Hari kerja</label>
                                        <input type="number" min="1" max="10" wire:model="days" class="{{ $input }}">
                                    </div>
                                </div>
                                @break
                            @case(\App\Enums\Scenario::DeliveryReturned)
                                <div class="grid grid-cols-3 gap-2">
                                    <div class="col-span-2">
                                        <label class="{{ $label }}">Request</label>
                                        <select wire:model="returnCode" class="{{ $input }}">
                                            @foreach ($returnable as $request)
                                                <option value="{{ $request->code }}">{{ $request->code }} · {{ \Illuminate\Support\Str::limit($request->title, 28) }}</option>
                                            @endforeach
                                        </select>
                                    </div>
                                    <div>
                                        <label class="{{ $label }}">Rework (jam)</label>
                                        <input type="number" step="0.5" min="0.5" wire:model="reworkHours" class="{{ $input }}">
                                    </div>
                                </div>
                                @break
                            @case(\App\Enums\Scenario::DependencyDelay)
                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="{{ $label }}">ETA mundur (jam kerja)</label>
                                        <input type="number" step="1" min="1" wire:model="etaDelayHours" class="{{ $input }}">
                                    </div>
                                    <div>
                                        <label class="{{ $label }}">Jam maju (jam kerja)</label>
                                        <input type="number" step="1" min="1" wire:model="delayClockHours" class="{{ $input }}">
                                    </div>
                                </div>
                                @break
                            @case(\App\Enums\Scenario::EstimateGrowth)
                                <div>
                                    <label class="{{ $label }}">Estimasi baru SR-103 (jam, awal 12)</label>
                                    <input type="number" step="1" min="1" wire:model="growthHours" class="{{ $input }}">
                                </div>
                                @break
                            @case(\App\Enums\Scenario::UrgentInsert)
                                <p class="rounded-lg bg-slate-50 p-2 text-xs text-slate-600">Membuat request baru (10 jam, data analysis, tenggat besok) lalu meng-override ke peringkat 1.</p>
                                @break
                            @default
                                <p class="rounded-lg bg-slate-50 p-2 text-xs text-slate-600">Tanpa parameter.</p>
                        @endswitch
                    </div>

                    <div class="mt-4 flex items-center gap-2">
                        @if ($locked)
                            <span class="rounded-full bg-violet-50 px-2 py-1 text-xs font-medium text-violet-700">🔒 Khusus Manager</span>
                        @else
                            <x-ui.button variant="ghost" wire:click="previewScenario('{{ $scenario->value }}')" target="previewScenario('{{ $scenario->value }}')" class="flex-1 justify-center">Preview</x-ui.button>
                            <x-ui.button wire:click="runScenario('{{ $scenario->value }}')" target="runScenario('{{ $scenario->value }}')"
                                wire:confirm="Jalankan {{ $scenario->code() }} sungguhan? Perubahan tersimpan dan tercatat di audit log." class="flex-1 justify-center">Jalankan</x-ui.button>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>

        {{-- Riwayat & reset --}}
        <div class="space-y-4">
            <x-ui.card title="Riwayat run" subtitle="Klik untuk melihat diff sebelum/sesudah.">
                <ol class="relative space-y-1 border-l border-slate-200 pl-3">
                    @forelse ($runs as $run)
                        @php $type = $run->scenarioType(); @endphp
                        <li wire:key="run-{{ $run->id }}">
                            <button type="button" wire:click="showRun({{ $run->id }})"
                                @class([
                                    'relative -ml-1 w-full rounded-lg px-2 py-1.5 text-left text-sm transition hover:bg-slate-50',
                                    'bg-indigo-50' => ($result['mode'] ?? null) === 'run' && $selectedRunId === $run->id,
                                ])>
                                <span class="absolute -left-[0.9rem] top-3 size-2.5 rounded-full border-2 border-white {{ $type === \App\Enums\Scenario::ClockAdvance ? 'bg-amber-400' : 'bg-indigo-500' }}"></span>
                                <span class="font-medium text-slate-800">{{ $type?->code() }} {{ $type?->label() ?? $run->scenario }}</span>
                                <span class="block text-xs text-slate-500">{{ $run->created_at?->translatedFormat('D d M H:i') }} · {{ $run->params['actor'] ?? 'Sistem' }}{{ isset($run->params['hours']) ? ' · '.$run->params['hours'].' jam' : '' }}</span>
                            </button>
                        </li>
                    @empty
                        <li class="text-sm text-slate-500">Belum ada run.</li>
                    @endforelse
                </ol>
            </x-ui.card>

            @if ($allowReset)
                <div class="animate-enter rounded-2xl border border-rose-200 bg-rose-50/50 p-4">
                    <h3 class="text-sm font-semibold text-rose-800">Reset data simulasi</h3>
                    <p class="mt-1 text-xs text-rose-700">Mengembalikan semua data ke kondisi awal soal (migrate:fresh --seed). Semua sesi login ikut keluar.</p>
                    <x-ui.button variant="danger" wire:click="resetData" target="resetData" wire:confirm="Reset semua data ke kondisi awal? Tindakan ini menghapus semua perubahan." class="mt-3 w-full justify-center">Reset data</x-ui.button>
                </div>
            @endif
        </div>
    </div>

    {{-- Hasil --}}
    @if ($result)
        @php $diff = $result['diff']; @endphp
        <section wire:key="result-{{ $result['mode'] }}-{{ $selectedRunId }}-{{ $result['scenario']?->value }}" x-data x-init="$nextTick(() => $el.scrollIntoView({ behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' }))" class="animate-enter scroll-mt-20 space-y-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div class="flex flex-wrap items-center gap-3">
                @if ($result['mode'] === 'preview')
                    <span class="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">PREVIEW · belum disimpan</span>
                @else
                    <span class="rounded-full bg-emerald-100 px-2.5 py-1 text-xs font-semibold text-emerald-800">DIJALANKAN · {{ $result['at']?->translatedFormat('D d M H:i') }}{{ $result['actor'] ? ' · '.$result['actor'] : '' }}</span>
                @endif
                <h2 class="text-lg font-semibold text-slate-900">{{ $result['scenario']?->code() }} {{ $result['scenario']?->label() }}</h2>
                @if ($diff['clock']['before'] !== $diff['clock']['after'])
                    <span class="rounded-full bg-slate-100 px-2.5 py-1 text-xs text-slate-600">🕘 {{ $fmt($diff['clock']['before']) }} → {{ $fmt($diff['clock']['after']) }}</span>
                @endif
                @if ($result['mode'] === 'preview' && $result['scenario'] && ! ($result['scenario']->requiresManager() && ! $isManager))
                    <x-ui.button wire:click="runScenario('{{ $result['scenario']->value }}')" target="runScenario('{{ $result['scenario']->value }}')" class="ml-auto">Jalankan sungguhan →</x-ui.button>
                @endif
            </div>

            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                @foreach ($diff['kpis'] as $kpi)
                    @php
                        $delta = $kpi['delta'];
                        $worse = $delta != 0 && (($delta > 0) === $kpi['higher_is_worse']);
                        $better = $delta != 0 && ! $worse;
                    @endphp
                    <div style="--i: {{ $loop->index }}" @class([
                        'animate-enter rounded-xl border p-3',
                        'border-rose-200 bg-rose-50/60' => $worse,
                        'border-emerald-200 bg-emerald-50/60' => $better,
                        'border-slate-200 bg-slate-50/50' => $delta == 0,
                    ])>
                        <p class="text-xs font-medium text-slate-500">{{ $kpi['label'] }}</p>
                        <p class="mt-1 flex items-baseline gap-2">
                            <span class="text-xl font-semibold tabular-nums text-slate-900" x-data="countUp(@js((string) $kpi['after']))" data-value="{{ $kpi['after'] }}" x-text="display">{{ $kpi['after'] }}</span>
                            @if ($delta != 0)
                                <span @class(['text-xs font-semibold tabular-nums', 'text-rose-700' => $worse, 'text-emerald-700' => $better])>{{ $delta > 0 ? '▲ +' : '▼ ' }}{{ $delta }}</span>
                            @endif
                        </p>
                        <p class="text-[11px] text-slate-400">sebelumnya {{ $kpi['before'] }}</p>
                    </div>
                @endforeach
            </div>

            <div class="grid gap-5 lg:grid-cols-3">
                <div class="lg:col-span-2">
                    <h3 class="mb-2 text-sm font-semibold text-slate-700">Request yang berubah ({{ count($diff['requests']) }})</h3>
                    <div class="overflow-x-auto rounded-xl border border-slate-200">
                        <table class="w-full text-sm">
                            <thead class="bg-slate-50 text-left text-xs text-slate-500">
                                <tr>
                                    <th class="px-3 py-2 font-medium">Request</th>
                                    <th class="px-3 py-2 font-medium">Peringkat</th>
                                    <th class="px-3 py-2 font-medium">Risiko</th>
                                    <th class="px-3 py-2 font-medium">Perkiraan selesai</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @forelse ($diff['requests'] as $row)
                                    <tr wire:key="diff-{{ $row['code'] }}" style="--i: {{ min($loop->index, 12) }}" @class(['animate-enter', 'bg-rose-50/40' => $row['worse'], 'bg-emerald-50/40' => $row['better']])>
                                        <td class="px-3 py-2">
                                            <a href="{{ route('requests.show', $row['code']) }}" wire:navigate class="font-mono text-xs text-indigo-700 hover:underline">{{ $row['code'] }}</a>
                                            @if ($row['is_new'])
                                                <span class="ml-1 rounded-full bg-indigo-100 px-1.5 py-0.5 text-[10px] font-semibold text-indigo-700">BARU</span>
                                            @endif
                                            <div class="text-slate-800">{{ $row['title'] }}</div>
                                            @if ($row['status_before'] !== $row['status_after'] && $row['status_after'])
                                                <div class="mt-0.5 flex items-center gap-1 text-xs">
                                                    @if ($row['status_before']) <x-ui.status-badge :status="$row['status_before']" /> → @endif
                                                    <x-ui.status-badge :status="$row['status_after']" />
                                                </div>
                                            @endif
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2 tabular-nums">
                                            #{{ $row['rank_before'] ?? '–' }} → <strong>#{{ $row['rank_after'] ?? '–' }}</strong>
                                            @if ($row['rank_before'] && $row['rank_after'] && $row['rank_before'] !== $row['rank_after'])
                                                <span @class(['ml-1 text-xs font-semibold', 'text-emerald-700' => $row['rank_after'] < $row['rank_before'], 'text-slate-500' => $row['rank_after'] > $row['rank_before']])>
                                                    {{ $row['rank_after'] < $row['rank_before'] ? '↑'.($row['rank_before'] - $row['rank_after']) : '↓'.($row['rank_after'] - $row['rank_before']) }}
                                                </span>
                                            @endif
                                        </td>
                                        <td class="px-3 py-2">
                                            <div class="flex flex-wrap items-center gap-1">
                                                <x-ui.risk-badge :level="$row['risk_before']" />
                                                @if ($row['risk_before'] !== $row['risk_after'])
                                                    <span class="text-slate-400">→</span> <x-ui.risk-badge :level="$row['risk_after']" />
                                                @endif
                                            </div>
                                        </td>
                                        <td class="whitespace-nowrap px-3 py-2 text-xs text-slate-600">{{ $fmt($row['finish_before']) }}<br>→ <span class="font-medium text-slate-800">{{ $fmt($row['finish_after']) }}</span></td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="px-3 py-4 text-center text-slate-500">Tidak ada request yang berubah.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="space-y-4">
                    <div>
                        <h3 class="mb-2 text-sm font-semibold text-slate-700">Rekomendasi baru ({{ count($diff['new_recommendations']) }})</h3>
                        <ul class="space-y-1.5">
                            @forelse ($diff['new_recommendations'] as $title)
                                <li style="--i: {{ $loop->index }}" class="animate-enter flex gap-2 rounded-lg bg-emerald-50 px-2.5 py-1.5 text-sm text-emerald-900"><span class="font-bold">+</span> {{ $title }}</li>
                            @empty
                                <li class="text-sm text-slate-500">–</li>
                            @endforelse
                        </ul>
                    </div>
                    <div>
                        <h3 class="mb-2 text-sm font-semibold text-slate-700">Rekomendasi ditutup ({{ count($diff['closed_recommendations']) }})</h3>
                        <ul class="space-y-1.5">
                            @forelse ($diff['closed_recommendations'] as $title)
                                <li class="flex gap-2 rounded-lg bg-slate-50 px-2.5 py-1.5 text-sm text-slate-500 line-through"><span class="font-bold no-underline">−</span> {{ $title }}</li>
                            @empty
                                <li class="text-sm text-slate-500">–</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </div>
        </section>
    @endif
</div>
