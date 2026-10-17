<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Services\Auth\LoginAttempt;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Fallback login lewat POST biasa — dipakai browser bila JavaScript Livewire gagal dimuat.
 */
class LoginController extends Controller
{
    public function store(Request $request, LoginAttempt $login): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $login->attempt($credentials['email'], $credentials['password'], $request->boolean('remember'), $request->ip());

        return redirect()->intended(route('home'));
    }
}
