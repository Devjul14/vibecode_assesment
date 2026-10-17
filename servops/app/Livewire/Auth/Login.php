<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\Auth\LoginAttempt;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::guest')]
#[Title('Masuk')]
class Login extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    /**
     * Bawa email & error dari fallback POST ke form, dan isi akun demo dari ?as=email
     * (dirender server, jadi tetap jalan walau JavaScript gagal dimuat).
     */
    public function mount(): void
    {
        $this->email = (string) old('email', '');

        foreach (session('errors')?->getBag('default')->messages() ?? [] as $field => $messages) {
            $this->addError($field, $messages[0]);
        }

        $demoEmail = (string) request()->query('as', '');

        if ($demoEmail !== '' && config('servops.demo_accounts') && User::query()->where('email', $demoEmail)->exists()) {
            $this->email = $demoEmail;
            $this->password = (string) config('servops.demo_password');
        }
    }

    public function login(LoginAttempt $login): void
    {
        $this->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $login->attempt($this->email, $this->password, $this->remember, request()->ip());

        $this->redirectIntended(route('home'), navigate: true);
    }

    public function render(): View
    {
        return view('livewire.auth.login', [
            'demoAccounts' => config('servops.demo_accounts')
                ? User::query()->orderByRaw("case role when 'manager' then 1 when 'team_lead' then 2 when 'member' then 3 else 4 end")->orderBy('name')->get(['name', 'email', 'role'])
                : collect(),
        ]);
    }
}
