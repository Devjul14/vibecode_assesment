@php
    $input = 'mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm';
    $label = 'block text-xs font-medium text-slate-600';
    $fmt = fn ($date) => $date?->translatedFormat('D d M H:i') ?? '–';
@endphp

<div class="space-y-5">
    <div>
        <a href="{{ route('requests.index') }}" wire:navigate class="text-sm text-indigo-700 hover:underline">← Requests</a>
        <div class="mt-1 flex flex-wrap items-center gap-2">
            <span class="font-mono text-sm text-slate-500">{{ $request->code }}</span>
            <h1 class="text-2xl font-semibold">{{ $request->title }}</h1>
        </div>
        <div class="mt-2 flex flex-wrap items-center gap-2 text-sm">
            <x-ui.status-badge :status="$request->status" />
            <x-ui.risk-badge :level="$request->risk_level" />
            @if ($request->priority_rank)
                <span class="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-medium text-indigo-700">Peringkat #{{ $request->priority_rank }} · skor {{ number_format($request->priority_score, 1) }}</span>
            @endif
            @if ($request->needs_human_review)
                <span class="rounded-full bg-violet-50 px-2 py-0.5 text-xs font-medium text-violet-700">Perlu review manusia</span>
            @endif
            @if ($request->isRework())
                <span class="rounded-full bg-orange-50 px-2 py-0.5 text-xs font-medium text-orange-700">Rework {{ $request->rework_count }}× · {{ $request->rework_cause?->label() }}</span>
            @endif
        </div>
        @if ($request->description)
            <p class="mt-2 max-w-3xl text-sm text-slate-600">{{ $request->description }}</p>
        @endif
        <dl class="mt-3 grid grid-cols-2 gap-x-6 gap-y-1 text-sm sm:grid-cols-4">
            <div><dt class="text-xs text-slate-500">Pemohon</dt><dd>{{ $request->requesterDepartment?->name ?? '–' }} · prioritas {{ $request->requester_priority->label() }}</dd></div>
            <div><dt class="text-xs text-slate-500">Proses bisnis</dt><dd>{{ $request->businessProcess?->name ?? '–' }}</dd></div>
            <div><dt class="text-xs text-slate-500">Kompetensi</dt><dd>{{ $request->requiredSkill?->name ?? 'belum ditentukan' }} (level ≥ {{ $request->required_level }})</dd></div>
            <div><dt class="text-xs text-slate-500">Assignee</dt><dd>{{ $request->assignee?->name ?? 'belum ditugaskan' }}{{ $request->pairingMember ? ' + mentor '.$request->pairingMember->name : '' }}</dd></div>
        </dl>
    </div>

    <div class="grid gap-5 lg:grid-cols-3">
        <div class="space-y-5 lg:col-span-2">
            <x-ui.card title="Mengapa prioritas ini?" subtitle="Skor = Σ bobot × nilai faktor (0–100). Prioritas pemohon hanya satu sinyal berbobot kecil.">
                @if ($request->priority_breakdown)
                    <div class="space-y-2.5">
                        @foreach ($request->priority_breakdown as $factor)
                            <div title="{{ $factor['label'] }}: nilai {{ $factor['raw'] }} × bobot {{ $factor['weight'] }} = {{ $factor['contribution'] }} poin">
                                <div class="flex flex-wrap items-baseline justify-between gap-2 text-sm">
                                    <span class="font-medium text-slate-700">{{ $factor['label'] }} <span class="text-xs font-normal text-slate-400">× {{ $factor['weight'] }}</span></span>
                                    <span class="tabular-nums text-slate-600">{{ $factor['raw'] }} → <strong class="text-slate-900">{{ $factor['contribution'] }}</strong></span>
                                </div>
                                <div class="mt-1 h-2 w-full rounded-full bg-slate-100">
                                    <div class="h-full rounded-full bg-series-1" style="width: 0" x-data x-grow="{{ $factor['raw'] }}"></div>
                                </div>
                                <p class="mt-0.5 text-xs text-slate-500">{{ $factor['note'] }}</p>
                            </div>
                        @endforeach
                    </div>
                @else
                    <p class="text-sm text-slate-500">Belum dihitung (request selesai atau belum masuk triage).</p>
                @endif

                @if ($request->review_reasons)
                    <h3 class="mb-2 mt-4 text-xs font-semibold uppercase tracking-wide text-violet-700">Perlu keputusan manusia</h3>
                    <x-ui.reasons :reasons="$request->review_reasons" />
                @endif
            </x-ui.card>

            <x-ui.card title="Risiko SLA" subtitle="Tenggat, estimasi, waktu tunggu, dan pengerjaan aktif dipisahkan; proyeksi dihitung dari antrean orang yang mengerjakan.">
                <dl class="grid grid-cols-2 gap-3 text-sm sm:grid-cols-4">
                    <div><dt class="text-xs text-slate-500">Tenggat</dt><dd class="font-medium">{{ $fmt($request->deadline_at) }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Perkiraan selesai</dt><dd class="font-medium">{{ $fmt($request->projected_finish_at) }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Mulai paling lambat</dt><dd class="font-medium">{{ $fmt($request->latest_start_at) }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Slack</dt><dd class="font-medium tabular-nums">{{ $request->slack_hours !== null ? $request->slack_hours.' jam kerja' : '–' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Estimasi total</dt><dd class="tabular-nums">{{ $request->estimate_hours !== null ? $request->estimate_hours.' jam' : 'belum diketahui' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Sisa effort (perencanaan)</dt><dd class="tabular-nums">{{ $planning['hours'] }} jam{{ $planning['assumed'] ? ' (asumsi)' : '' }}</dd></div>
                    <div><dt class="text-xs text-slate-500">Pengerjaan aktif</dt><dd class="tabular-nums">{{ $request->actual_hours }} jam</dd></div>
                    <div><dt class="text-xs text-slate-500">Waktu tunggu</dt><dd class="tabular-nums">{{ $waitHours }} jam kerja</dd></div>
                </dl>
                <div class="mt-4">
                    <x-ui.reasons :reasons="$request->risk_reasons ?? []" empty="Tidak ada pemicu risiko." />
                </div>
            </x-ui.card>

            @if (auth()->user()->can('requests.view_all') && ($candidates->isNotEmpty() || $pairingOptions->isNotEmpty()))
                <x-ui.card title="Kandidat penugasan" subtitle="Hanya anggota yang memenuhi syarat kompetensi & tersedia. Anggota di bawah syarat hanya muncul sebagai opsi pairing.">
                    <div class="overflow-x-auto">
                        <table class="w-full text-sm">
                            <thead class="text-left text-xs text-slate-500">
                                <tr>
                                    <th class="pb-2 pr-3 font-medium">Anggota</th>
                                    <th class="pb-2 pr-3 text-right font-medium" title="Skor kecocokan 0–100">Fit</th>
                                    <th class="pb-2 pr-3 font-medium">Alasan</th>
                                    <th class="pb-2"></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 align-top">
                                @foreach ($candidates as $candidate)
                                    <tr wire:key="candidate-{{ $candidate->member->id }}">
                                        <td class="py-2 pr-3">
                                            <div class="font-medium text-slate-800">{{ $candidate->member->name }}</div>
                                            <div class="text-xs text-slate-500">{{ $candidate->member->role_title }}</div>
                                            @if ($loop->first)
                                                <span class="mt-1 inline-block rounded-full bg-emerald-50 px-1.5 py-0.5 text-[11px] font-medium text-emerald-700">terbaik</span>
                                            @endif
                                        </td>
                                        <td class="py-2 pr-3 text-right tabular-nums">{{ $candidate->fit }}</td>
                                        <td class="py-2 pr-3 text-xs text-slate-600">
                                            <ul class="space-y-0.5">
                                                @foreach ($candidate->reasons as $reasonText)
                                                    <li>{{ $reasonText }}</li>
                                                @endforeach
                                                @foreach ($candidate->warnings as $warning)
                                                    <li class="text-amber-700">⚠ {{ $warning }}</li>
                                                @endforeach
                                            </ul>
                                        </td>
                                        <td class="py-2 text-right">
                                            @if (($canAssign || $canReassign) && $request->assignee_id !== $candidate->member->id)
                                                <x-ui.button variant="ghost" size="sm" wire:click="assignTo({{ $candidate->member->id }})" target="assignTo({{ $candidate->member->id }})" class="whitespace-nowrap">{{ $canAssign ? 'Tugaskan' : 'Pindahkan' }}</x-ui.button>
                                            @elseif ($request->assignee_id === $candidate->member->id)
                                                <span class="text-xs text-slate-500">assignee saat ini</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                                @foreach ($pairingOptions as $option)
                                    <tr class="bg-amber-50/40">
                                        <td class="py-2 pr-3">
                                            <div class="font-medium text-slate-800">{{ $option->member->name }}</div>
                                            <span class="mt-1 inline-block rounded-full bg-amber-100 px-1.5 py-0.5 text-[11px] font-medium text-amber-800">opsi pairing</span>
                                        </td>
                                        <td class="py-2 pr-3 text-right text-slate-400">–</td>
                                        <td class="py-2 pr-3 text-xs text-slate-600" colspan="2">
                                            Level {{ $option->skillLevel }} (syarat {{ $request->required_level }}), kapasitas {{ $option->availableHours }} jam. Hanya bisa ditugaskan dengan mentor kompeten + alasan override (form "Ubah status" → Ditugaskan).
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </x-ui.card>
            @endif

            <x-ui.card title="Riwayat" subtitle="Semua perubahan status, estimasi, penugasan, dan dependensi — dengan pelaku dan alasan.">
                <ol class="relative space-y-3 border-l border-slate-200 pl-4">
                    @foreach ($history as $entry)
                        <li class="text-sm">
                            <span class="absolute -left-1.5 mt-1.5 size-3 rounded-full border-2 border-white {{ ($entry->meta['event'] ?? null) ? 'bg-slate-300' : 'bg-indigo-500' }}"></span>
                            <div class="flex flex-wrap items-center gap-2">
                                <span class="text-xs tabular-nums text-slate-500">{{ $entry->created_at->translatedFormat('D d M H:i') }}</span>
                                @if ($event = $entry->meta['event'] ?? null)
                                    <span class="font-medium text-slate-700">{{ str_replace('_', ' ', $event) }}</span>
                                @else
                                    @if ($entry->from_status)
                                        <x-ui.status-badge :status="$entry->from_status" /> <span class="text-slate-400">→</span>
                                    @endif
                                    <x-ui.status-badge :status="$entry->to_status" />
                                @endif
                                <span class="text-xs text-slate-500">oleh {{ $entry->actor_name ?? $entry->actor_role->label() }} ({{ $entry->actor_role->label() }})</span>
                            </div>
                            @if ($entry->reason)
                                <p class="mt-0.5 text-slate-600">“{{ $entry->reason }}”</p>
                            @endif
                            @php $details = collect($entry->meta ?? [])->except(['event', 'warnings'])->filter(fn ($v) => is_scalar($v)); @endphp
                            @if ($details->isNotEmpty())
                                <p class="mt-0.5 text-xs text-slate-500">{{ $details->map(fn ($v, $k) => str_replace('_', ' ', $k).': '.(is_bool($v) ? ($v ? 'ya' : 'tidak') : $v))->implode(' · ') }}</p>
                            @endif
                            @foreach ($entry->meta['warnings'] ?? [] as $warning)
                                <p class="mt-0.5 text-xs text-amber-700">⚠ {{ $warning['message'] ?? $warning }}</p>
                            @endforeach
                        </li>
                    @endforeach
                </ol>
            </x-ui.card>
        </div>

        <div class="space-y-5">
            @if ($recommendations->isNotEmpty())
                <x-ui.card title="Rekomendasi">
                    <ul class="space-y-3">
                        @foreach ($recommendations as $recommendation)
                            <li class="text-sm" wire:key="rec-{{ $recommendation->id }}">
                                <p class="font-medium text-slate-800">{{ $recommendation->title }}</p>
                                <p class="mt-0.5 text-xs text-slate-600">{{ $recommendation->rationale }}</p>
                                @can('recommendations.decide')
                                    <div class="mt-2 flex gap-2">
                                        <x-ui.button size="sm" wire:click="acceptRecommendation({{ $recommendation->id }})" target="acceptRecommendation({{ $recommendation->id }})">Terima</x-ui.button>
                                        <x-ui.button variant="ghost" size="sm" wire:click="dismissRecommendation({{ $recommendation->id }})" target="dismissRecommendation({{ $recommendation->id }})">Abaikan</x-ui.button>
                                    </div>
                                @endcan
                            </li>
                        @endforeach
                    </ul>
                </x-ui.card>
            @endif

            @if ($transitions || $canAssign)
                <x-ui.card title="Ubah status" subtitle="Aturan workflow ditegakkan; pelanggaran ditampilkan sebagai pesan.">
                    <form wire:submit="changeStatus" class="space-y-3">
                        <div>
                            <label class="{{ $label }}" for="target">Status tujuan</label>
                            <select id="target" wire:model.live="target" class="{{ $input }}">
                                <option value="">— pilih —</option>
                                @if ($canAssign)
                                    <option value="assigned">{{ \App\Enums\RequestStatus::Assigned->label() }}</option>
                                @endif
                                @foreach ($transitions as $status)
                                    <option value="{{ $status->value }}">{{ $status->label() }}</option>
                                @endforeach
                            </select>
                        </div>

                        @if ($target === 'assigned')
                            <div wire:transition>
                                <label class="{{ $label }}" for="assignee">Assignee</label>
                                <select id="assignee" wire:model="assigneeId" class="{{ $input }}">
                                    <option value="">— pilih —</option>
                                    @foreach ($members as $member)
                                        <option value="{{ $member->id }}">{{ $member->name }} (L{{ $request->required_skill_id ? $member->skillLevel($request->required_skill_id) : '-' }})</option>
                                    @endforeach
                                </select>
                            </div>
                            <details wire:transition class="rounded-md border border-amber-200 bg-amber-50/50 p-2 text-xs">
                                <summary class="cursor-pointer font-medium text-amber-800">Di bawah syarat? Pairing dengan mentor</summary>
                                <label class="{{ $label }} mt-2" for="mentor">Mentor (kompeten)</label>
                                <select id="mentor" wire:model="pairingMemberId" class="{{ $input }}">
                                    <option value="">— tanpa mentor —</option>
                                    @foreach ($members as $member)
                                        <option value="{{ $member->id }}">{{ $member->name }}</option>
                                    @endforeach
                                </select>
                                <label class="{{ $label }} mt-2" for="overrideReason">Alasan override</label>
                                <input id="overrideReason" type="text" wire:model="overrideReason" class="{{ $input }}">
                            </details>
                        @endif

                        @if ($target === 'returned')
                            <div wire:transition class="grid grid-cols-2 gap-2">
                                <div>
                                    <label class="{{ $label }}" for="reworkHours">Estimasi rework (jam)</label>
                                    <input id="reworkHours" type="number" step="0.5" min="0" wire:model="reworkHours" class="{{ $input }}">
                                </div>
                                <div>
                                    <label class="{{ $label }}" for="reworkCause">Penyebab</label>
                                    <select id="reworkCause" wire:model="reworkCause" class="{{ $input }}">
                                        <option value="">— pilih —</option>
                                        @foreach ($reworkCauses as $value => $name)
                                            <option value="{{ $value }}">{{ $name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                            </div>
                        @endif

                        @if ($target === 'blocked')
                            <div wire:transition class="space-y-2 rounded-md border border-slate-200 p-2">
                                <p class="text-xs text-slate-500">Dependensi (opsional jika alasan diisi)</p>
                                <input type="text" wire:model="depParty" placeholder="Pihak eksternal, mis. Tim Finance" class="{{ $input }}">
                                <select wire:model="depOnRequestId" class="{{ $input }}">
                                    <option value="">— atau menunggu request lain —</option>
                                    @foreach ($otherRequests as $other)
                                        <option value="{{ $other->id }}">{{ $other->code }} · {{ $other->title }}</option>
                                    @endforeach
                                </select>
                                <input type="text" wire:model="depDescription" placeholder="Apa yang ditunggu" class="{{ $input }}">
                                <label class="{{ $label }}">ETA</label>
                                <input type="datetime-local" wire:model="depEta" class="{{ $input }}">
                            </div>
                        @endif

                        <div>
                            <label class="{{ $label }}" for="reason">Alasan</label>
                            <textarea id="reason" wire:model="reason" rows="2" class="{{ $input }}"></textarea>
                        </div>
                        <x-ui.button type="submit" target="changeStatus" class="w-full justify-center">Terapkan</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if ($canReassign)
                <x-ui.card title="Pindahkan penugasan" subtitle="Mis. assignee mendadak tidak tersedia. Aturan kompetensi sama dengan penugasan awal.">
                    <form wire:submit="reassign" class="space-y-2">
                        <select wire:model="reassignTo" class="{{ $input }}">
                            <option value="">— pilih anggota —</option>
                            @foreach ($members->where('id', '!=', $request->assignee_id) as $member)
                                <option value="{{ $member->id }}">{{ $member->name }} (L{{ $request->required_skill_id ? $member->skillLevel($request->required_skill_id) : '-' }})</option>
                            @endforeach
                        </select>
                        <input type="text" wire:model="reassignReason" placeholder="Alasan (wajib)" class="{{ $input }}">
                        <x-ui.button type="submit" variant="ghost" target="reassign" class="w-full justify-center">Pindahkan</x-ui.button>
                    </form>
                </x-ui.card>
            @endif

            @if ($request->status->isOpen())
                @can('assessImpact', $request)
                    <x-ui.card :title="$request->impact_level === \App\Enums\ImpactLevel::Unknown ? 'Triage dampak (wajib)' : 'Nilai ulang dampak'" subtitle="Dampak bisnis menentukan 35% skor prioritas. Saat ini: {{ $request->impact_level->label() }}.">
                        <form wire:submit="assessImpact" class="space-y-2">
                            <select wire:model="impactLevel" class="{{ $input }}">
                                <option value="">— tingkat dampak —</option>
                                @foreach ($impactLevels as $value => $name)
                                    <option value="{{ $value }}">{{ $name }}</option>
                                @endforeach
                            </select>
                            @error('impactLevel') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                            <div class="grid grid-cols-2 gap-2">
                                <input type="number" min="0" wire:model="impactDepartments" placeholder="Dept. terdampak" class="{{ $input }}">
                                <input type="number" min="0" wire:model="impactUsers" placeholder="Pengguna terdampak" class="{{ $input }}">
                            </div>
                            <input type="text" wire:model="impactReason" placeholder="Temuan triage (wajib)" class="{{ $input }}">
                            <x-ui.button type="submit" variant="ghost" target="assessImpact" class="w-full justify-center">Simpan penilaian</x-ui.button>
                        </form>
                    </x-ui.card>
                @endcan

                @can('reviseEstimate', $request)
                <x-ui.card title="Revisi estimasi" subtitle="Estimasi total. Sisa effort ikut bergeser sebesar selisihnya.">
                    <form wire:submit="reviseEstimate" class="space-y-2">
                        <input type="number" step="0.5" min="0" wire:model="newEstimate" placeholder="Estimasi baru (jam), saat ini {{ $request->estimate_hours ?? '?' }}" class="{{ $input }}">
                        @error('newEstimate') <p class="text-xs text-rose-600">{{ $message }}</p> @enderror
                        <input type="text" wire:model="estimateReason" placeholder="Alasan (wajib)" class="{{ $input }}">
                        <label class="flex items-center gap-2 text-xs text-slate-600"><input type="checkbox" wire:model="scopeChange" class="rounded border-slate-300"> Karena perubahan scope</label>
                        <x-ui.button type="submit" variant="ghost" target="reviseEstimate" class="w-full justify-center">Simpan estimasi</x-ui.button>
                    </form>
                    @if ($request->estimateRevisions->isNotEmpty())
                        <ul class="mt-3 space-y-1 border-t border-slate-100 pt-2 text-xs text-slate-600">
                            @foreach ($request->estimateRevisions as $revision)
                                <li>
                                    <span class="tabular-nums">{{ $revision->old_hours ?? '–' }} → {{ $revision->new_hours }} j</span>
                                    <span class="rounded bg-slate-100 px-1">{{ $revision->kind }}</span>
                                    {{ $revision->scope_change ? '· scope' : '' }} — {{ $revision->reason }}
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </x-ui.card>
                @endcan

                <x-ui.card title="Dependensi">
                    <ul class="space-y-2 text-sm">
                        @forelse ($request->dependencies as $dependency)
                            <li class="flex items-start justify-between gap-2" wire:key="dep-{{ $dependency->id }}">
                                <div>
                                    <p class="font-medium {{ $dependency->resolved_at ? 'text-slate-400 line-through' : 'text-slate-800' }}">{{ $dependency->external_party ?? $dependency->dependsOn?->code }}</p>
                                    <p class="text-xs text-slate-500">{{ $dependency->description }} · ETA {{ $fmt($dependency->expected_at) }}</p>
                                </div>
                                @if (! $dependency->resolved_at && auth()->user()->can('manageDependencies', $request))
                                    <x-ui.button variant="ghost" size="sm" wire:click="resolveDependency({{ $dependency->id }})" target="resolveDependency({{ $dependency->id }})" class="shrink-0">Selesai</x-ui.button>
                                @endif
                            </li>
                        @empty
                            <li class="text-slate-500">Tidak ada dependensi.</li>
                        @endforelse
                    </ul>
                    @can('manageDependencies', $request)
                    <details class="mt-3 text-sm">
                        <summary class="cursor-pointer text-xs font-medium text-indigo-700">+ Tambah dependensi</summary>
                        <form wire:submit="addDependency" class="mt-2 space-y-2">
                            <input type="text" wire:model="depParty" placeholder="Pihak eksternal" class="{{ $input }}">
                            <select wire:model="depOnRequestId" class="{{ $input }}">
                                <option value="">— atau request lain —</option>
                                @foreach ($otherRequests as $other)
                                    <option value="{{ $other->id }}">{{ $other->code }} · {{ $other->title }}</option>
                                @endforeach
                            </select>
                            <input type="text" wire:model="depDescription" placeholder="Apa yang ditunggu" class="{{ $input }}">
                            <input type="datetime-local" wire:model="depEta" class="{{ $input }}">
                            <x-ui.button type="submit" variant="ghost" target="addDependency" class="w-full justify-center">Tambah</x-ui.button>
                        </form>
                    </details>
                    @endcan
                </x-ui.card>

                @can('override', $request)
                <x-ui.card title="Override prioritas" subtitle="Khusus manager. Lihat dampaknya ke pekerjaan lain sebelum menyimpan.">
                    @if ($activeOverride)
                        <div class="mb-3 rounded-md bg-violet-50 p-2 text-xs text-violet-800">
                            Aktif: peringkat {{ $activeOverride->forced_rank }} oleh {{ $activeOverride->approved_by }} — “{{ $activeOverride->reason }}”
                            <button type="button" wire:click="revokeOverride({{ $activeOverride->id }})" class="ml-1 underline">Cabut</button>
                        </div>
                    @endif
                    <div class="space-y-2">
                        <div class="flex items-center gap-2 text-sm">
                            <label for="overrideRank" class="text-xs text-slate-600">Paksa ke peringkat</label>
                            <input id="overrideRank" type="number" min="1" wire:model="overrideRank" class="w-20 rounded-md border border-slate-300 px-2 py-1 text-sm">
                        </div>
                        <input type="text" wire:model="overrideJustification" placeholder="Alasan bisnis (wajib)" class="{{ $input }}">
                        <div class="flex gap-2">
                            <x-ui.button variant="ghost" wire:click="previewOverride" target="previewOverride" class="flex-1 justify-center">Preview dampak</x-ui.button>
                            <x-ui.button wire:click="applyOverride" target="applyOverride" class="flex-1 justify-center">Simpan override</x-ui.button>
                        </div>
                    </div>
                    @if ($preview !== null)
                        <div wire:transition class="mt-3 border-t border-slate-100 pt-2">
                            <p class="mb-1 text-xs font-semibold text-slate-700">Dampak (belum disimpan)</p>
                            @forelse ($preview as $row)
                                <div class="flex flex-wrap items-center gap-1.5 py-1 text-xs {{ $row['worse'] ? 'text-rose-700' : 'text-slate-600' }}">
                                    <span class="font-mono">{{ $row['code'] }}</span>
                                    <span class="tabular-nums">#{{ $row['rank_before'] ?? '–' }} → #{{ $row['rank_after'] ?? '–' }}</span>
                                    <x-ui.risk-badge :level="$row['risk_before']" /> <span>→</span> <x-ui.risk-badge :level="$row['risk_after']" />
                                </div>
                            @empty
                                <p class="text-xs text-slate-500">Tidak ada perubahan pada request lain.</p>
                            @endforelse
                        </div>
                    @endif
                </x-ui.card>
                @endcan
            @endif
        </div>
    </div>
</div>
