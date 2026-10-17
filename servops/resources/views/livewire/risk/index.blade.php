@php $fmt = fn ($date) => $date?->translatedFormat('D d M H:i') ?? '–'; @endphp

<div class="space-y-4">
    <div>
        <h1 class="text-2xl font-semibold">Risiko SLA</h1>
        <p class="text-sm text-slate-500">Risiko dihitung dari proyeksi antrean orang yang mengerjakan — terlihat sebelum tenggat lewat. Slack = jam kerja tersisa − sisa effort.</p>
    </div>

    <div class="flex flex-wrap gap-2 text-sm">
        <button type="button" wire:click="$set('level', '')" @class(['rounded-full border px-3 py-1', 'border-indigo-300 bg-indigo-50 text-indigo-700' => $level === '', 'border-slate-200 bg-white text-slate-600' => $level !== ''])>Semua</button>
        @foreach ($levels as $option)
            <button type="button" wire:click="$set('level', '{{ $option->value }}')" @class(['rounded-full border px-1 py-0.5', 'border-indigo-300 bg-indigo-50' => $level === $option->value, 'border-transparent' => $level !== $option->value])>
                <x-ui.risk-badge :level="$option" />
            </button>
        @endforeach
    </div>

    <div class="overflow-x-auto rounded-xl border border-slate-200 bg-white transition-opacity" wire:loading.class="opacity-50" wire:target="level">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                <tr>
                    <th class="px-3 py-2 font-medium">Request</th>
                    <th class="px-3 py-2 font-medium">Risiko</th>
                    <th class="px-3 py-2 font-medium">Tenggat</th>
                    <th class="px-3 py-2 text-right font-medium" title="Jam kerja sampai tenggat / sisa effort aktif">Waktu / effort</th>
                    <th class="px-3 py-2 text-right font-medium">Slack</th>
                    <th class="px-3 py-2 font-medium">Mulai paling lambat / perkiraan selesai</th>
                    <th class="px-3 py-2 text-right font-medium" title="Waktu tunggu (jam kerja), termasuk yang sedang berjalan">Tunggu</th>
                    <th class="px-3 py-2 font-medium">Alasan & tindakan</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100 align-top">
                @forelse ($requests as $request)
                    <tr wire:key="risk-{{ $request->id }}" style="--i: {{ min($loop->index, 15) }}" class="animate-enter transition-colors hover:bg-slate-50">
                        <td class="px-3 py-2">
                            <a href="{{ route('requests.show', $request) }}" wire:navigate class="font-mono text-xs text-indigo-700 hover:underline">{{ $request->code }}</a>
                            <div class="font-medium text-slate-800">{{ $request->title }}</div>
                            <div class="mt-0.5 flex items-center gap-1"><x-ui.status-badge :status="$request->status" /> <span class="text-xs text-slate-500">{{ $request->assignee?->name ?? 'belum ditugaskan' }}</span></div>
                        </td>
                        <td class="px-3 py-2"><x-ui.risk-badge :level="$request->risk_level" /></td>
                        <td class="whitespace-nowrap px-3 py-2">{{ $fmt($request->deadline_at) }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-right tabular-nums">{{ $hoursLeft[$request->id] }} j<div class="text-xs text-slate-500">{{ $request->remaining_hours ?? '?' }} j effort</div></td>
                        <td @class(['whitespace-nowrap px-3 py-2 text-right font-medium tabular-nums', 'text-rose-700' => ($request->slack_hours ?? 0) < 0])>{{ $request->slack_hours !== null ? round($request->slack_hours, 1) : '–' }} j</td>
                        <td class="whitespace-nowrap px-3 py-2">{{ $fmt($request->latest_start_at) }}<div class="text-xs text-slate-500">selesai {{ $fmt($request->projected_finish_at) }}</div></td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $waitHours[$request->id] }} j</td>
                        <td class="w-96 px-3 py-2">
                            @forelse ($request->risk_reasons ?? [] as $reason)
                                <p class="text-xs text-slate-700"><span class="font-medium">{{ \App\Data\Reason::label($reason['code']) }}:</span> {{ $reason['message'] }}</p>
                                @if ($reason['action'])
                                    <p class="mb-1 text-xs text-indigo-700">→ {{ $reason['action'] }}</p>
                                @endif
                            @empty
                                <span class="text-xs text-slate-400">–</span>
                            @endforelse
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="10" class="px-3 py-6 text-center text-slate-500">Tidak ada request.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
