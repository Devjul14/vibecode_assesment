@php
    $input = 'mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm';
    $label = 'block text-xs font-medium text-slate-600';
@endphp

<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold">Tim & kapasitas</h1>
        <p class="text-sm text-slate-500">Kapasitas efektif = jam kerja × fokus − rutin − rapat − cuti/sakit. Beban = sisa effort request yang ditugaskan.</p>
    </div>

    @if ($unassigned->isNotEmpty())
        <div class="animate-enter flex flex-wrap items-center gap-x-4 gap-y-1 rounded-xl border border-sky-200 bg-sky-50/60 px-4 py-3 text-sm">
            <span class="font-semibold text-sky-900">Belum ditugaskan: {{ $unassigned->count() }} request · {{ round($unassigned->sum('remaining_hours'), 1) }} jam{{ $unassigned->whereNull('remaining_hours')->isNotEmpty() ? ' + estimasi belum diketahui' : '' }}</span>
            <span class="text-sky-800">Beban di bawah hanya menghitung pekerjaan yang sudah ditugaskan — utilisasi rendah belum tentu berarti tim longgar.</span>
        </div>
    @endif

    <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
        @foreach ($members as $member)
            @php $row = $snapshot[$member->id]; @endphp
            <div class="animate-enter rounded-xl border border-slate-200 bg-white p-4 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md" style="--i: {{ $loop->index }}" wire:key="member-{{ $member->id }}">
                <div class="flex items-start justify-between gap-2">
                    <div>
                        <p class="font-semibold text-slate-800">{{ $member->name }}</p>
                        <p class="text-xs text-slate-500">{{ $member->role_title }} · {{ $member->seniority->label() }}</p>
                    </div>
                    @if ($row['absent_now'])
                        <span class="rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-medium text-rose-700">tidak hadir</span>
                    @endif
                </div>
                <x-ui.meter class="mt-3" :value="$row['committed']" :max="max(0.01, $row['capacity'])"
                    :caption="$row['committed'].' / '.$row['capacity'].' jam · sisa '.$row['available'].' jam'" />
                @if ($canTake[$member->id])
                    <p class="mt-2 text-xs text-sky-800">Kompeten mengambil: <span class="font-medium">{{ implode(', ', $canTake[$member->id]) }}</span></p>
                @endif
                <p class="mt-2 text-xs text-slate-500">Efektif {{ $member->baseEffectiveDailyHours() }} jam/hari (fokus {{ $member->focus_factor * 100 }}%, rapat {{ $member->meeting_hours_per_day }}, rutin {{ $member->routine_hours_per_day }})</p>
            </div>
        @endforeach
    </div>

    <x-ui.card title="Kapasitas efektif per hari" subtitle="Jam efektif yang tersedia (sebelum dikurangi beban). Hari ini dihitung dari sisa jam kerja.">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-slate-500">
                    <tr>
                        <th class="pb-2 font-medium">Anggota</th>
                        @foreach ($days as $day)
                            <th class="pb-2 text-center font-medium">{{ $day->translatedFormat('D d M') }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody>
                    @foreach ($members as $member)
                        <tr>
                            <td class="py-1 pr-3 font-medium text-slate-700">{{ $member->name }}</td>
                            @foreach ($heatmap[$member->id] as $cell)
                                @php
                                    $ratio = $member->baseEffectiveDailyHours() > 0 ? $cell['hours'] / $member->baseEffectiveDailyHours() : 0;
                                    $shade = match (true) { $ratio >= 0.9 => 'bg-[#256abf] text-white', $ratio >= 0.6 => 'bg-[#5598e7] text-white', $ratio >= 0.3 => 'bg-[#9ec5f4] text-slate-800', $ratio > 0 => 'bg-[#cde2fb] text-slate-700', default => 'bg-slate-100 text-slate-500' };
                                @endphp
                                <td class="p-0.5">
                                     <div class="rounded px-2 py-1.5 text-center text-xs tabular-nums transition-transform duration-150 hover:scale-105 {{ $shade }}" title="{{ $member->name }}: {{ $cell['hours'] }} jam efektif{{ $cell['absence'] ? ' — '.$cell['absence']->label() : '' }}">
                                        {{ $cell['absence'] ? $cell['absence']->label() : $cell['hours'].' j' }}
                                    </div>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </x-ui.card>

    <div class="grid gap-6 lg:grid-cols-2">
        <x-ui.card title="Matriks kompetensi" subtitle="Level 1 = basic, 2 = mandiri, 3 = expert. Kolom dengan satu angka ≥ 2 = single point of failure.">
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="text-left text-xs text-slate-500">
                        <tr>
                            <th class="pb-2 font-medium">Skill</th>
                            @foreach ($members as $member)
                                <th class="pb-2 text-center font-medium">{{ $member->name }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($skills as $skill)
                            <tr>
                                <td class="py-1.5 pr-3 text-slate-700">{{ $skill->name }}</td>
                                @foreach ($members as $member)
                                    @php $level = $member->skillLevel($skill); @endphp
                                    <td class="py-1.5 text-center">
                                        @if ($level > 0)
                                            <span @class([
                                                'inline-flex size-6 items-center justify-center rounded text-xs font-semibold tabular-nums',
                                                'bg-[#256abf] text-white' => $level === 3,
                                                'bg-[#86b6ef] text-slate-900' => $level === 2,
                                                'bg-[#cde2fb] text-slate-700' => $level === 1,
                                            ])>{{ $level }}</span>
                                        @else
                                            <span class="text-slate-300">·</span>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </x-ui.card>

        <x-ui.card title="Permintaan vs pasokan per skill" subtitle="Sisa effort yang membutuhkan skill ÷ kapasitas anggota kompeten (level ≥ {{ config('servops.capacity.qualified_min_level') }}), {{ config('servops.capacity.horizon_days') }} hari kerja.">
            <div class="space-y-3">
                @foreach ($skillBalance as $row)
                    <x-ui.meter :value="$row['demand']" :max="max(0.01, $row['supply'])" :label="$row['skill']->name"
                        :caption="$row['demand'].' / '.$row['supply'].' jam · '.(implode(', ', $row['qualified_members']) ?: 'tidak ada anggota kompeten')" />
                @endforeach
            </div>
        </x-ui.card>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        @if ($manageableMembers->isNotEmpty())
        <x-ui.card title="Catat ketidakhadiran / rapat" subtitle="Langsung memengaruhi proyeksi, risiko, dan rekomendasi.">
            <form wire:submit="addEntry" class="space-y-2">
                <div>
                    <label class="{{ $label }}" for="member">Anggota</label>
                    <select id="member" wire:model="memberId" class="{{ $input }}">
                        <option value="">— pilih —</option>
                        @foreach ($manageableMembers as $member)
                            <option value="{{ $member->id }}">{{ $member->name }}</option>
                        @endforeach
                    </select>
                    @error('memberId') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $label }}" for="type">Jenis</label>
                    <select id="type" wire:model="type" class="{{ $input }}">
                        @foreach ($types as $value => $name)
                            <option value="{{ $value }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="{{ $label }}" for="startsAt">Mulai</label>
                        <input id="startsAt" type="datetime-local" wire:model="startsAt" class="{{ $input }}">
                    </div>
                    <div>
                        <label class="{{ $label }}" for="endsAt">Selesai</label>
                        <input id="endsAt" type="datetime-local" wire:model="endsAt" class="{{ $input }}">
                    </div>
                </div>
                <div>
                    <label class="{{ $label }}" for="hours">Jam (opsional, untuk rapat parsial)</label>
                    <input id="hours" type="number" step="0.5" min="0" wire:model="hours" class="{{ $input }}">
                </div>
                <input type="text" wire:model="note" placeholder="Catatan" class="{{ $input }}">
                <x-ui.button type="submit" target="addEntry" class="w-full justify-center">Simpan</x-ui.button>
            </form>
        </x-ui.card>
        @endif

        <x-ui.card @class(['lg:col-span-2' => $manageableMembers->isNotEmpty(), 'lg:col-span-3' => $manageableMembers->isEmpty()]) title="Ketidakhadiran & rapat mendatang">
            <table class="w-full text-sm">
                <thead class="text-left text-xs text-slate-500">
                    <tr><th class="pb-2 font-medium">Anggota</th><th class="pb-2 font-medium">Jenis</th><th class="pb-2 font-medium">Waktu</th><th class="pb-2 font-medium">Catatan</th><th></th></tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($entries as $entry)
                        <tr wire:key="entry-{{ $entry->id }}">
                            <td class="py-2 font-medium text-slate-800">{{ $entry->member->name }}</td>
                            <td class="py-2">{{ $entry->type->label() }}{{ $entry->hours ? ' ('.$entry->hours.' j)' : '' }}</td>
                            <td class="py-2 text-slate-600">{{ $entry->starts_at->translatedFormat('D d M H:i') }} – {{ $entry->ends_at->translatedFormat('D d M H:i') }}</td>
                            <td class="py-2 text-slate-600">{{ $entry->note }}</td>
                            <td class="py-2 text-right">
                                @if ($this->canManageCapacityOf($entry->team_member_id))
                                    <button type="button" wire:click="removeEntry({{ $entry->id }})" wire:confirm="Hapus entri ini?" class="text-xs text-rose-700 hover:underline">Hapus</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="py-2 text-slate-500">Tidak ada.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </x-ui.card>
    </div>
</div>
