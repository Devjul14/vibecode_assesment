<?php

namespace App\Models;

use App\Data\Actor;
use App\Enums\ActorRole;
use App\Enums\Permission;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'role', 'team_member_id', 'department_id', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'role' => ActorRole::class,
        ];
    }

    /**
     * Profil anggota tim (untuk peran anggota).
     *
     * @return BelongsTo<TeamMember, $this>
     */
    public function teamMember(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class);
    }

    /**
     * Departemen asal (untuk peran pemohon).
     *
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function hasPermission(Permission $permission): bool
    {
        return $this->role->can($permission);
    }

    /** Pelaku untuk audit log. */
    public function actor(): Actor
    {
        return new Actor($this->role, $this->name);
    }

    /** Request ini ditugaskan ke anggota yang terhubung dengan user. */
    public function isAssigneeOf(ServiceRequest $request): bool
    {
        return $this->team_member_id !== null && $request->assignee_id === $this->team_member_id;
    }

    /** Request ini berasal dari departemen user. */
    public function isRequesterOf(ServiceRequest $request): bool
    {
        return $this->department_id !== null && $request->requester_department_id === $this->department_id;
    }

    public function initials(): string
    {
        return collect(explode(' ', $this->name))->take(2)->map(fn (string $part) => mb_substr($part, 0, 1))->implode('');
    }
}
