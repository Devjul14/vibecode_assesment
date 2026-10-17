<?php

namespace App\Services;

use App\Data\Actor;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * Pelaku perubahan untuk audit log: user yang sedang login, atau Sistem (console, scheduler).
 */
class ActorContext
{
    public function current(): Actor
    {
        $user = Auth::user();

        return $user instanceof User ? $user->actor() : Actor::system();
    }
}
