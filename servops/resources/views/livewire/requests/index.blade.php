<div class="space-y-4">
    <div class="flex flex-wrap items-end justify-between gap-2">
        <div>
            <h1 class="text-2xl font-semibold">Requests</h1>
            <p class="text-sm text-slate-500">Urut ranking prioritas sistem. Klik baris untuk melihat alasan skor, risiko, dan aksi.</p>
        </div>
        @can('create', \App\Models\ServiceRequest::class)
            <a href="{{ route('requests.create') }}" wire:navigate class="rounded-lg bg-indigo-600 px-3 py-2 text-sm font-medium text-white shadow-sm transition hover:-translate-y-0.5 hover:bg-indigo-700 hover:shadow-md">+ Request baru</a>
        @endcan
    </div>

    <div class="animate-enter flex flex-wrap items-center gap-2 rounded-xl border border-slate-200 bg-white p-3 text-sm">
        <input type="search" wire:model.live.debounce.300ms="search" placeholder="Cari kode / judul…" class="w-48 rounded-md border border-slate-300 px-2 py-1.5">
        <select wire:model.live="scope" class="rounded-md border border-slate-300 py-1.5 pl-2 pr-7">
            <option value="open">Semua yang terbuka</option>
            <option value="done">Selesai (diterima)</option>
            <option value="all">Semua</option>
            <optgroup label="Status">
                @foreach ($statuses as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </optgroup>
        </select>
        <select wire:model.live="risk" class="rounded-md border border-slate-300 py-1.5 pl-2 pr-7">
            <option value="">Semua risiko</option>
            @foreach ($riskLevels as $value => $label)
                <option value="{{ $value }}">{{ $label }}</option>
            @endforeach
        </select>
        <select wire:model.live="skill" class="rounded-md border border-slate-300 py-1.5 pl-2 pr-7">
            <option value="">Semua skill</option>
            @foreach ($skills as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
        <select wire:model.live="assignee" class="rounded-md border border-slate-300 py-1.5 pl-2 pr-7">
            <option value="">Semua assignee</option>
            <option value="none">Belum ditugaskan</option>
            @foreach ($members as $id => $name)
                <option value="{{ $id }}">{{ $name }}</option>
            @endforeach
        </select>
        <label class="flex items-center gap-1.5 text-slate-600">
            <input type="checkbox" wire:model.live="reviewOnly" class="rounded border-slate-300"> Perlu review saja
        </label>
    </div>

    <div class="relative overflow-x-auto rounded-xl border border-slate-200 bg-white transition-opacity duration-200" wire:loading.class="opacity-50" wire:target="search, scope, risk, skill, assignee, reviewOnly, gotoPage, nextPage, previousPage">
        <table class="w-full text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs text-slate-500">
                <tr>
                    <th class="px-3 py-2 font-medium">#</th>
                    <th class="px-3 py-2 font-medium">Request</th>
                    <th class="px-3 py-2 font-medium">Status</th>
                    <th class="px-3 py-2 text-right font-medium" title="Skor prioritas 0–100">Skor</th>
                    <th class="px-3 py-2 font-medium">Risiko</th>
                    <th class="px-3 py-2 font-medium">Assignee</th>
                    <th class="px-3 py-2 font-medium">Tenggat</th>
                    <th class="px-3 py-2 text-right font-medium" title="Sisa effort (jam)">Sisa</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($requests as $request)
                    <tr wire:key="request-{{ $request->id }}" style="--i: {{ min($loop->index, 15) }}" class="animate-enter cursor-pointer transition-colors hover:bg-indigo-50/40" @click="Livewire.navigate('{{ route('requests.show', $request) }}')">
                        <td class="px-3 py-2 tabular-nums text-slate-400">{{ $request->priority_rank ?? '–' }}</td>
                        <td class="px-3 py-2">
                            <a href="{{ route('requests.show', $request) }}" class="font-mono text-xs text-slate-500">{{ $request->code }}</a>
                            <span class="font-medium text-slate-800">{{ $request->title }}</span>
                            @if ($request->needs_human_review)
                                <span class="ml-1 rounded-full bg-violet-50 px-1.5 py-0.5 text-[11px] font-medium text-violet-700">review</span>
                            @endif
                            @if ($request->isRework())
                                <span class="ml-1 rounded-full bg-orange-50 px-1.5 py-0.5 text-[11px] font-medium text-orange-700">rework {{ $request->rework_count }}×</span>
                            @endif
                            <div class="text-xs text-slate-500">{{ $request->requesterDepartment?->name }} · {{ $request->requiredSkill?->name ?? 'skill belum ditentukan' }} (L{{ $request->required_level }}) · pemohon: {{ $request->requester_priority->label() }}</div>
                        </td>
                        <td class="px-3 py-2"><x-ui.status-badge :status="$request->status" /></td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $request->priority_score !== null ? number_format($request->priority_score, 1) : '–' }}</td>
                        <td class="px-3 py-2"><x-ui.risk-badge :level="$request->risk_level" /></td>
                        <td class="px-3 py-2 text-slate-700">{{ $request->assignee?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap px-3 py-2 text-slate-700">{{ $request->deadline_at->translatedFormat('D d M H:i') }}</td>
                        <td class="px-3 py-2 text-right tabular-nums">{{ $request->remaining_hours !== null ? $request->remaining_hours.' j' : '?' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-6 text-center text-slate-500">Tidak ada request yang cocok.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $requests->links() }}
</div>
