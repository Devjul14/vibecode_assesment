<div class="w-full max-w-md">
    <div class="animate-enter mb-8 text-center" style="--i: 0">
        <div class="mx-auto mb-4 flex size-14 items-center justify-center rounded-2xl bg-gradient-to-br from-indigo-500 to-violet-600 text-2xl font-bold text-white shadow-lg shadow-indigo-500/30">S</div>
        <h1 class="text-2xl font-semibold text-slate-900">Masuk ke ServOps</h1>
        <p class="mt-1 text-sm text-slate-500">Service Operations & Capacity Planning</p>
    </div>

    <form wire:submit="login" method="POST" action="{{ route('login.store') }}" class="animate-enter space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-xl shadow-slate-200/60" style="--i: 1">
        @csrf
        <div>
            <label for="email" class="block text-sm font-medium text-slate-700">Email</label>
            <input id="email" name="email" type="email" value="{{ $email }}" wire:model="email" autocomplete="username" autofocus
                class="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm transition focus:border-indigo-500 focus:outline-none focus:ring-4 focus:ring-indigo-100">
            @error('email') <p class="mt-1 text-xs text-rose-600" wire:transition>{{ $message }}</p> @enderror
        </div>
        <div x-data="{ show: false }">
            <label for="password" class="block text-sm font-medium text-slate-700">Password</label>
            <div class="relative mt-1">
                <input id="password" name="password" type="password" value="{{ $password }}" :type="show ? 'text' : 'password'" wire:model="password" autocomplete="current-password"
                    class="w-full rounded-lg border border-slate-300 px-3 py-2 pr-16 text-sm transition focus:border-indigo-500 focus:outline-none focus:ring-4 focus:ring-indigo-100">
                <button type="button" @click="show = !show" class="absolute inset-y-0 right-2 my-auto h-7 rounded px-2 text-xs text-slate-500 hover:bg-slate-100" x-text="show ? 'Sembunyi' : 'Lihat'"></button>
            </div>
            @error('password') <p class="mt-1 text-xs text-rose-600">{{ $message }}</p> @enderror
        </div>
        <label class="flex items-center gap-2 text-sm text-slate-600">
            <input type="checkbox" name="remember" value="1" wire:model="remember" class="rounded border-slate-300 text-indigo-600"> Ingat saya
        </label>
        <x-ui.button type="submit" target="login" class="w-full justify-center py-2.5">Masuk</x-ui.button>
    </form>

    @if ($demoAccounts->isNotEmpty())
        <div class="animate-enter mt-6 rounded-2xl border border-dashed border-slate-300 bg-white/60 p-4" style="--i: 2">
            <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Akun demo · password: <code class="rounded bg-slate-100 px-1">{{ config('servops.demo_password') }}</code></p>
            <div class="mt-3 grid grid-cols-2 gap-2">
                @foreach ($demoAccounts as $account)
                    {{-- Link biasa (?as=email) agar tetap mengisi form walau JavaScript gagal dimuat; dengan JS diisi instan. --}}
                    <a href="{{ route('login', ['as' => $account->email]) }}"
                        x-on:click.prevent="$wire.email = @js($account->email); $wire.password = @js(config('servops.demo_password')); $nextTick(() => document.getElementById('password').focus())"
                        data-demo-email="{{ $account->email }}"
                        class="group flex items-center gap-2 rounded-lg border border-slate-200 bg-white px-2.5 py-2 text-left transition hover:-translate-y-0.5 hover:border-indigo-300 hover:shadow-md">
                        <span class="flex size-7 shrink-0 items-center justify-center rounded-full bg-indigo-50 text-[11px] font-semibold text-indigo-700 transition group-hover:bg-indigo-600 group-hover:text-white">{{ $account->initials() }}</span>
                        <span class="min-w-0">
                            <span class="block truncate text-xs font-medium text-slate-800">{{ $account->name }}</span>
                            <span class="block text-[11px] text-slate-500">{{ $account->role->label() }}</span>
                        </span>
                    </a>
                @endforeach
            </div>
        </div>
    @endif
</div>
