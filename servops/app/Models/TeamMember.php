<?php

namespace App\Models;

use App\Enums\RequestStatus;
use App\Enums\Seniority;
use Database\Factories\TeamMemberFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'name', 'role_title', 'seniority', 'daily_hours', 'focus_factor',
    'routine_hours_per_day', 'meeting_hours_per_day', 'wip_limit', 'is_active',
])]
class TeamMember extends Model
{
    /** @use HasFactory<TeamMemberFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'seniority' => Seniority::class,
            'daily_hours' => 'float',
            'focus_factor' => 'float',
            'routine_hours_per_day' => 'float',
            'meeting_hours_per_day' => 'float',
            'wip_limit' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    /** @return BelongsToMany<Skill, $this> */
    public function skills(): BelongsToMany
    {
        return $this->belongsToMany(Skill::class, 'member_skills')
            ->withPivot('level')
            ->withTimestamps();
    }

    /** @return HasMany<MemberSkill, $this> */
    public function memberSkills(): HasMany
    {
        return $this->hasMany(MemberSkill::class);
    }

    /** @return HasMany<ServiceRequest, $this> */
    public function assignedRequests(): HasMany
    {
        return $this->hasMany(ServiceRequest::class, 'assignee_id');
    }

    /** @return HasMany<CapacityEntry, $this> */
    public function capacityEntries(): HasMany
    {
        return $this->hasMany(CapacityEntry::class);
    }

    /** @return HasMany<TimeLog, $this> */
    public function timeLogs(): HasMany
    {
        return $this->hasMany(TimeLog::class);
    }

    /** @param  Builder<TeamMember>  $query */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Request yang dihitung sebagai beban aktif anggota ini.
     *
     * @return HasMany<ServiceRequest, $this>
     */
    public function activeLoad(): HasMany
    {
        return $this->assignedRequests()->whereIn('status', RequestStatus::loadStatuses());
    }

    /** Level kompetensi untuk satu skill (0 = tidak punya). */
    public function skillLevel(Skill|int $skill): int
    {
        $skillId = $skill instanceof Skill ? $skill->id : $skill;

        $match = $this->relationLoaded('skills')
            ? $this->skills->firstWhere('id', $skillId)
            : $this->skills()->whereKey($skillId)->first();

        return (int) ($match?->pivot->level ?? 0);
    }

    public function isQualifiedFor(ServiceRequest $request): bool
    {
        if ($request->required_skill_id === null) {
            return true;
        }

        return $this->skillLevel($request->required_skill_id) >= $request->required_level;
    }

    /**
     * Jam efektif per hari sebelum cuti / entri kapasitas khusus:
     * daily_hours × focus_factor − rutin − rapat.
     */
    public function baseEffectiveDailyHours(): float
    {
        return max(0.0, $this->daily_hours * $this->focus_factor
            - $this->routine_hours_per_day
            - $this->meeting_hours_per_day);
    }
}
