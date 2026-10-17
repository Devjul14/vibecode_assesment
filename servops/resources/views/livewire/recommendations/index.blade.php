<div class="space-y-6">
    <div>
        <h1 class="text-2xl font-semibold">Rekomendasi</h1>
        <p class="text-sm text-slate-500">Disusun otomatis dari prioritas, risiko, dan kapasitas. Rekomendasi penugasan yang diterima langsung dijalankan lewat workflow (dengan aturan kompetensi yang sama). Yang kondisinya hilang ditutup otomatis.</p>
    </div>

    <div class="space-y-3">
        @forelse ($open as $recommendation)
            <div class="animate-enter rounded-xl border border-slate-200 bg-white p-4 transition-all duration-200 hover:-translate-y-0.5 hover:shadow-md" style="--i: {{ min($loop->index, 12) }}" wire:key="open-{{ $recommendation->id }}" wire:transition>
                <div class="flex flex-wrap items-start gap-3">
                    <span @class([
                        'mt-0.5 inline-flex shrink-0 items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium',
                        'bg-rose-50 text-rose-800' => $recommendation->severity >= 3,
                        'bg-amber-50 text-amber-800' => $recommendation->severity === 2,
                        'bg-sky-50 text-sky-800' => $recommendation->severity <= 1,
                    ])>
                        {{ ['', 'Info', 'Penting', 'Kritis'][$recommendation->severity] ?? $recommendation->severity }}
                    </span>
                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <p class="font-semibold text-slate-800">{{ $recommendation->title }}</p>
                            <span class="rounded bg-slate-100 px-1.5 py-0.5 text-[11px] text-slate-600">{{ $recommendation->type->label() }}</span>
                            @if ($recommendation->serviceRequest)
                                <a href="{{ route('requests.show', $recommendation->serviceRequest) }}" wire:navigate class="font-mono text-xs text-indigo-700 hover:underline">{{ $recommendation->serviceRequest->code }}</a>
                            @endif
                        </div>
                        <p class="mt-1 text-sm text-slate-600">{{ $recommendation->rationale }}</p>
                    </div>
                    @can('recommendations.decide')
                        <div class="flex w-full shrink-0 flex-col gap-2 sm:w-56">
                            <x-ui.button wire:click="accept({{ $recommendation->id }})" target="accept({{ $recommendation->id }})" class="justify-center">Terima{{ in_array($recommendation->payload['action'] ?? null, ['assign', 'reassign'], true) ? ' & jalankan' : '' }}</x-ui.button>
                            <div class="flex gap-1">
                                <input type="text" wire:model="notes.{{ $recommendation->id }}" placeholder="Catatan (opsional)" class="min-w-0 flex-1 rounded-lg border border-slate-300 px-2 py-1 text-xs">
                                <x-ui.button variant="ghost" size="sm" wire:click="dismiss({{ $recommendation->id }})" target="dismiss({{ $recommendation->id }})">Abaikan</x-ui.button>
                            </div>
                        </div>
                    @endcan
                </div>
            </div>
        @empty
            <p class="rounded-lg border border-slate-200 bg-white p-4 text-sm text-slate-500">Tidak ada rekomendasi terbuka.</p>
        @endforelse
    </div>

    <x-ui.card title="Riwayat keputusan" subtitle="30 keputusan terakhir — termasuk yang ditutup otomatis karena kondisinya sudah tidak berlaku.">
        <table class="w-full text-sm">
            <thead class="text-left text-xs text-slate-500">
                <tr><th class="pb-2 font-medium">Rekomendasi</th><th class="pb-2 font-medium">Status</th><th class="pb-2 font-medium">Oleh</th><th class="pb-2 font-medium">Waktu</th></tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @forelse ($decided as $recommendation)
                    <tr>
                        <td class="py-2 text-slate-800">{{ $recommendation->title }}</td>
                        <td class="py-2">{{ $recommendation->status->label() }}</td>
                        <td class="py-2 text-slate-600">{{ $recommendation->decided_by }}</td>
                        <td class="py-2 text-slate-600">{{ $recommendation->decided_at?->translatedFormat('D d M H:i') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="py-2 text-slate-500">Belum ada keputusan.</td></tr>
                @endforelse
            </tbody>
        </table>
    </x-ui.card>
</div>
