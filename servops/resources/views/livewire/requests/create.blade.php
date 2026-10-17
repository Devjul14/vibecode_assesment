<div class="mx-auto max-w-3xl space-y-4">
    <div>
        <a href="{{ route('requests.index') }}" wire:navigate class="text-sm text-indigo-700 hover:underline">← Requests</a>
        <h1 class="mt-1 text-2xl font-semibold">Request baru</h1>
        <p class="text-sm text-slate-500">Isi yang diketahui. Dampak "belum diketahui" dan estimasi kosong boleh — sistem menandainya untuk triage & klarifikasi.</p>
    </div>

    @php
        $input = 'mt-1 w-full rounded-md border border-slate-300 px-2 py-1.5 text-sm';
        $label = 'block text-sm font-medium text-slate-700';
    @endphp

    <form wire:submit="save" class="animate-enter space-y-5 rounded-xl border border-slate-200 bg-white p-5">
        <div>
            <label class="{{ $label }}" for="title">Judul</label>
            <input id="title" type="text" wire:model="title" class="{{ $input }}">
            @error('title') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <div>
            <label class="{{ $label }}" for="description">Deskripsi</label>
            <textarea id="description" wire:model="description" rows="3" class="{{ $input }}"></textarea>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <div>
                <label class="{{ $label }}" for="category">Kategori</label>
                <select id="category" wire:model="category" class="{{ $input }}">
                    @foreach (\App\Livewire\Requests\Create::CATEGORIES as $value)
                        <option value="{{ $value }}">{{ $value }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="{{ $label }}" for="dept">Departemen pemohon</label>
                <select id="dept" wire:model="requester_department_id" class="{{ $input }} disabled:bg-slate-100" @disabled($lockedDepartment)>
                    <option value="">— pilih —</option>
                    @foreach ($departments as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
                @error('requester_department_id') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
            </div>
            <div>
                <label class="{{ $label }}" for="prio">Prioritas menurut pemohon</label>
                <select id="prio" wire:model="requester_priority" class="{{ $input }}">
                    @foreach ($priorities as $value => $name)
                        <option value="{{ $value }}">{{ $name }}</option>
                    @endforeach
                </select>
            </div>
        </div>

        <fieldset class="rounded-md border border-slate-200 p-4">
            <legend class="px-1 text-sm font-semibold text-slate-700">Dampak bisnis</legend>
            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="{{ $label }}" for="impact">Tingkat dampak</label>
                    <select id="impact" wire:model="impact_level" class="{{ $input }}">
                        @foreach ($impacts as $value => $name)
                            <option value="{{ $value }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $label }}" for="process">Proses bisnis terdampak</label>
                    <select id="process" wire:model="business_process_id" class="{{ $input }}">
                        <option value="">— tidak diketahui —</option>
                        @foreach ($processes as $process)
                            <option value="{{ $process->id }}">{{ $process->name }} (kritikalitas {{ $process->criticality }}/5)</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $label }}" for="users">Pengguna terdampak</label>
                    <input id="users" type="number" min="0" wire:model="affected_users" class="{{ $input }}">
                </div>
                <div>
                    <label class="{{ $label }}" for="depts">Departemen terdampak</label>
                    <input id="depts" type="number" min="0" wire:model="affected_departments" class="{{ $input }}">
                </div>
            </div>
        </fieldset>

        <fieldset class="rounded-md border border-slate-200 p-4">
            <legend class="px-1 text-sm font-semibold text-slate-700">Kebutuhan & estimasi</legend>
            <div class="grid gap-4 sm:grid-cols-3">
                <div>
                    <label class="{{ $label }}" for="skill">Kompetensi</label>
                    <select id="skill" wire:model="required_skill_id" class="{{ $input }}">
                        <option value="">— belum ditentukan —</option>
                        @foreach ($skills as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="{{ $label }}" for="level">Level minimum</label>
                    <select id="level" wire:model="required_level" class="{{ $input }}">
                        <option value="1">1 — basic</option>
                        <option value="2">2 — mandiri</option>
                        <option value="3">3 — expert</option>
                    </select>
                </div>
                <div>
                    <label class="{{ $label }}" for="deadline">Tenggat</label>
                    <input id="deadline" type="datetime-local" wire:model="deadline_at" class="{{ $input }}">
                    @error('deadline_at') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $label }}" for="estimate">Estimasi (jam)</label>
                    <input id="estimate" type="number" step="0.5" min="0" wire:model="estimate_hours" placeholder="kosong = belum diketahui" class="{{ $input }}">
                    @error('estimate_hours') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="{{ $label }}" for="confidence">Keyakinan estimasi</label>
                    <select id="confidence" wire:model="estimate_confidence" class="{{ $input }}">
                        @foreach ($confidences as $value => $name)
                            <option value="{{ $value }}">{{ $name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
        </fieldset>

        <div class="flex justify-end gap-2">
            <a href="{{ route('requests.index') }}" wire:navigate class="rounded-md px-3 py-2 text-sm text-slate-600 hover:bg-slate-100">Batal</a>
            <x-ui.button type="submit" target="save" class="px-4 py-2">Simpan & kirim ke triage</x-ui.button>
        </div>
    </form>
</div>
