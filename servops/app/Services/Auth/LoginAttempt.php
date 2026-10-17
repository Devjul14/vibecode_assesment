<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Satu jalur login untuk form Livewire dan fallback POST tanpa JavaScript.
 *
 * Dibatasi per email+IP dan per email saja: IP bisa dipalsukan lewat header proxy,
 * jadi batas per email mencegah brute force dengan IP yang berganti-ganti.
 */
class LoginAttempt
{
    private const MAX_ATTEMPTS_PER_IP = 5;

    private const MAX_ATTEMPTS_PER_EMAIL = 10;

    /**
     * @throws ValidationException
     */
    public function attempt(string $email, string $password, bool $remember, ?string $ip): User
    {
        $email = Str::lower(trim($email));
        $keys = [
            $email.'|'.$ip => self::MAX_ATTEMPTS_PER_IP,
            'email|'.$email => self::MAX_ATTEMPTS_PER_EMAIL,
        ];

        foreach ($keys as $key => $maxAttempts) {
            if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
                throw ValidationException::withMessages([
                    'email' => 'Terlalu banyak percobaan. Coba lagi dalam '.RateLimiter::availableIn($key).' detik.',
                ]);
            }
        }

        if (! Auth::attempt(['email' => $email, 'password' => $password], $remember)) {
            foreach (array_keys($keys) as $key) {
                RateLimiter::hit($key);
            }

            throw ValidationException::withMessages(['email' => 'Email atau password salah.']);
        }

        foreach (array_keys($keys) as $key) {
            RateLimiter::clear($key);
        }

        session()->regenerate();

        /** @var User $user */
        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->save();

        return $user;
    }
}
