<?php

namespace App\Models;

use App\Enums\CapacityEntryType;
use Carbon\CarbonInterface;
use Database\Factories\CapacityEntryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['team_member_id', 'type', 'starts_at', 'ends_at', 'hours', 'note'])]
class CapacityEntry extends Model
{
    /** @use HasFactory<CapacityEntryFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => CapacityEntryType::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'hours' => 'float',
        ];
    }

    /** @return BelongsTo<TeamMember, $this> */
    public function member(): BelongsTo
    {
        return $this->belongsTo(TeamMember::class, 'team_member_id');
    }

    /** @param  Builder<CapacityEntry>  $query */
    #[Scope]
    protected function overlapping(Builder $query, CarbonInterface $from, CarbonInterface $to): void
    {
        $query->where('starts_at', '<', $to)->where('ends_at', '>', $from);
    }

    /** Ketidakhadiran penuh (cuti / sakit) — anggota tidak bisa ditugaskan. */
    public function isAbsence(): bool
    {
        return in_array($this->type, [CapacityEntryType::Leave, CapacityEntryType::Sick], true);
    }
}
