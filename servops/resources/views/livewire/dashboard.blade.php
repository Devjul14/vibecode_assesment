<div class="space-y-6" wire:poll.30s.visible>
    <div class="animate-enter flex flex-wrap items-end justify-between gap-2">
        <div>
            <h1 class="flex items-center gap-2 text-2xl font-semibold">Dashboard keputusan
                <span class="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-medium text-emerald-700" title="Diperbarui otomatis tiap 30 detik">
                    <span class="relative flex size-1.5"><span class="absolute inline-flex size-full animate-ping rounded-full bg-emerald-400"></span><span class="relative inline-flex size-1.5 rounded-full bg-emerald-500"></span></span>
                    Live
                </span>
            </h1>
            <p class="text-sm text-slate-500">Apa yang berisiko, mengapa, di mana bottleneck, dan tindakan apa yang layak dipertimbangkan.</p>
        </div>
        <div class="flex flex-wrap gap-2 text-xs">
            @foreach (\App\Enums\RiskLevel::cases() as $level)
                <span class="inline-flex items-center gap-1"><x-ui.risk-badge :level="$level" /> <span class="tabular-nums text-slate-600">{{ $riskDistribution[$level->value] }}</span></span>
            @endforeach
        </div>
    </div>

    <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-7">
        @foreach ([
            'backlog' => 'Backlog aktif', 'at_risk' => 'Berisiko', 'review' => 'Perlu review', 'awaiting' => 'Menunggu acceptance',
            'on_time' => 'Tepat waktu', 'rework_rate' => 'Rework rate', 'flow_efficiency' => 'Flow efficiency',
        ] as $key => $kpiLabel)
            <x-ui.stat class="animate-enter" style="--i: {{ $loop->index + 1 }}" :label="$kpiLabel" :value="$kpis[$key]['value']" :definition="$kpis[$key]['definition']" :tone="$kpis[$key]['tone'] ?? null" />
        @endforeach
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card class="lg:col-span-2" title="Perlu perhatian sekarang" subtitle="Request berisiko atau butuh keputusan manusia, urut keparahan lalu prioritas.">
            @forelse ($attention as $request)
                @php $reason = ($request->risk_reasons ?? [])[0] ?? ($request->review_reasons ?? [])[0] ?? null; @endphp
                <a href="{{ route('requests.show', $request) }}" wire:navigate wire:key="attention-{{ $request->id }}" style="--i: {{ $loop->index }}" class="animate-enter -mx-2 flex flex-wrap items-start gap-3 rounded-lg px-2 py-2.5 transition-all duration-150 hover:translate-x-1 hover:bg-slate-50 [&:not(:first-child)]:border-t [&:not(:first-child)]:border-slate-100">
                    <span class="w-8 shrink-0 pt-0.5 text-right text-xs tabular-nums text-slate-400">#{{ $request->priority_rank }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-mono text-xs text-slate-500">{{ $request->code }}</span>
                            <span class="font-medium text-slate-800">{{ $request->title }}</span>
                            <x-ui.risk-badge :level="$request->risk_level" />
                            @if ($request->needs_human_review)
                                <span class="rounded-full bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-700">Perlu review</span>
                            @endif
                        </div>
                        @if ($reason)
                            <p class="mt-0.5 text-sm text-slate-600">{{ $reason['message'] }}</p>
                            @if (! empty($reason['action']))
                                <p class="text-xs text-indigo-700">→ {{ $reason['action'] }}</p>
                            @endif
                        @endif
                    </div>
                    <span class="shrink-0 text-xs text-slate-500">Tenggat {{ $request->deadline_at->translatedFormat('D d M H:i') }}</span>
                </a>
            @empty
                <p class="text-sm text-slate-500">Tidak ada request berisiko. 🎉</p>
            @endforelse
        </x-ui.card>

        <x-ui.card title="Rekomendasi teratas" subtitle="Tindakan yang disarankan sistem — keputusan tetap di manusia.">
            <x-slot:actions>
                <a href="{{ route('recommendations.index') }}" wire:navigate class="text-xs text-indigo-700 hover:underline">Semua →</a>
            </x-slot:actions>
            <ul class="space-y-3">
                @forelse ($recommendations as $recommendation)
                    <li class="animate-enter text-sm" style="--i: {{ $loop->index }}" wire:key="top-rec-{{ $recommendation->id }}">
                        <div class="flex items-start gap-2">
                            <span @class([
                                'mt-0.5 inline-flex size-4 shrink-0 items-center justify-center rounded-full text-[10px] font-bold text-white',
                                'bg-status-critical' => $recommendation->severity >= 3,
                                'bg-status-warning' => $recommendation->severity === 2,
                                'bg-series-1' => $recommendation->severity <= 1,
                            ]) aria-label="Severity {{ $recommendation->severity }}">{{ $recommendation->severity }}</span>
                            <div class="min-w-0 flex-1">
                                <p class="font-medium text-slate-800">{{ $recommendation->title }}</p>
                                <p class="text-xs text-slate-500">{{ $recommendation->type->label() }}</p>
                            </div>
                            @can('recommendations.decide')
                                <x-ui.button size="sm" wire:click="acceptRecommendation({{ $recommendation->id }})" target="acceptRecommendation({{ $recommendation->id }})" class="shrink-0">Terima</x-ui.button>
                            @endcan
                        </div>
                    </li>
                @empty
                    <li class="text-sm text-slate-500">Tidak ada rekomendasi terbuka.</li>
                @endforelse
            </ul>
        </x-ui.card>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <x-ui.card title="Mengapa pekerjaan berisiko" subtitle="Jumlah request berisiko per penyebab (satu request bisa punya beberapa penyebab).">
            @php $maxCause = max(1, collect($causes)->max('count')); @endphp
            <ul class="space-y-2.5">
                @forelse ($causes as $cause)
                    <li class="group relative" title="{{ $cause['label'] }}: {{ $cause['count'] }} request ({{ implode(', ', $cause['requests']) }})">
                        <div class="mb-1 flex justify-between gap-2 text-sm">
                            <span class="text-slate-700">{{ $cause['label'] }}</span>
                            <span class="tabular-nums text-slate-600">{{ $cause['count'] }}</span>
                        </div>
                        <div class="h-2 w-full rounded-full bg-slate-100">
                            <div class="h-full rounded-full bg-series-1 transition-opacity group-hover:opacity-80" style="width: 0" x-data x-grow="{{ round($cause['count'] / $maxCause * 100, 1) }}"></div>
                        </div>
                        <p class="mt-0.5 text-[11px] text-slate-400">{{ implode(', ', $cause['requests']) }}</p>
                    </li>
                @empty
                    <li class="text-sm text-slate-500">Tidak ada request berisiko.</li>
                @endforelse
            </ul>
        </x-ui.card>

        <x-ui.card title="Kapasitas tim" subtitle="Beban (sisa effort) ÷ kapasitas efektif {{ config('servops.capacity.horizon_days') }} hari kerja.">
            <x-slot:actions>
                <a href="{{ route('team.index') }}" wire:navigate class="text-xs text-indigo-700 hover:underline">Detail →</a>
            </x-slot:actions>
            <div class="space-y-3">
                @foreach ($team as $row)
                    <x-ui.meter :value="$row['committed']" :max="max(0.01, $row['capacity'])" :label="$row['member']->name.($row['absent_now'] ? ' (tidak hadir)' : '')"
                        :caption="$row['committed'].' / '.$row['capacity'].' jam · sisa '.$row['available'].' jam'" />
                @endforeach
            </div>
        </x-ui.card>

        <x-ui.card title="Bottleneck kompetensi" subtitle="Permintaan jam ÷ pasokan jam anggota kompeten (level ≥ {{ config('servops.capacity.qualified_min_level') }}).">
            <div class="space-y-3">
                @foreach ($skills->where('demand', '>', 0) as $row)
                    <x-ui.meter :value="$row['demand']" :max="max(0.01, $row['supply'])" :label="$row['skill']->name"
                        :caption="$row['demand'].' / '.$row['supply'].' jam · '.(implode(', ', $row['qualified_members']) ?: 'tidak ada anggota kompeten')" />
                @endforeach
            </div>
        </x-ui.card>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Hambatan dependensi" subtitle="Jam kerja menunggu per pihak untuk dependensi yang masih terbuka.">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-slate-500">
                    <tr><th class="pb-2 font-medium">Pihak</th><th class="pb-2 font-medium">Request</th><th class="pb-2 text-right font-medium">Menunggu</th><th class="pb-2 text-right font-medium">ETA</th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($blockers as $blocker)
                        <tr>
                            <td class="py-2 font-medium text-slate-800">{{ $blocker['party'] }}</td>
                            <td class="py-2 text-slate-600">{{ implode(', ', $blocker['requests']) }}</td>
                            <td class="py-2 text-right tabular-nums">{{ $blocker['waiting_hours'] }} jam</td>
                            <td class="py-2 text-right text-slate-600">{{ $blocker['eta'] ?? 'tanpa ETA' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="py-2 text-slate-500">Tidak ada dependensi terbuka.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>

        <x-ui.card title="Akurasi estimasi (historis)" subtitle="Median & P80 durasi aktual setelah outlier dibuang (IQR); rasio = aktual ÷ estimasi.">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-slate-500">
                        <tr>
                            <th class="pb-2 font-medium">Kategori</th>
                            <th class="pb-2 text-right font-medium">n</th>
                            <th class="pb-2 text-right font-medium">Median</th>
                            <th class="pb-2 text-right font-medium">P80</th>
                            <th class="pb-2 text-right font-medium" title="Rata-rata mentah termasuk outlier">Rata² mentah</th>
                            <th class="pb-2 text-right font-medium">Rasio</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 tabular-nums">
                        @foreach ($accuracy as $row)
                            <tr>
                                <td class="py-2 font-medium text-slate-800">{{ $row['category'] }}</td>
                                <td class="py-2 text-right">{{ $row['n'] }}<span class="text-xs text-slate-400">{{ $row['outliers'] ? ' (−'.$row['outliers'].')' : '' }}</span></td>
                                <td class="py-2 text-right">{{ $row['median'] }} j</td>
                                <td class="py-2 text-right">{{ $row['p80'] }} j</td>
                                <td class="py-2 text-right text-slate-400">{{ $row['mean_raw'] }} j</td>
                                <td class="py-2 text-right">{{ $row['accuracy_median'] }}×</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs text-slate-500">Outlier ekstrem tidak dipakai untuk estimasi — rata-rata mentah ditampilkan untuk menunjukkan seberapa jauh ia menyesatkan.</p>
        </x-ui.card>
    </div>
</div>
