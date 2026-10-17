<?php

namespace App\Services\Capacity;

use App\Enums\CapacityEntryType;
use App\Exceptions\WorkflowException;
use App\Models\CapacityEntry;
use App\Models\TeamMember;
use App\Services\Metrics\MetricsRecomputer;
use Carbon\CarbonImmutable;

/**
 * Cuti, sakit, rapat khusus — setiap perubahan langsung memengaruhi proyeksi & risiko.
 */
class CapacityEntryService
{
    public function __construct(private MetricsRecomputer $recomputer) {}

    public function add(TeamMember $member, CapacityEntryType $type, CarbonImmutable $startsAt, CarbonImmutable $endsAt, ?float $hours = null, ?string $note = null): CapacityEntry
    {
        if ($endsAt->lessThanOrEqualTo($startsAt)) {
            throw new WorkflowException('INVALID_RANGE', 'Waktu selesai harus setelah waktu mulai.');
        }

        $entry = CapacityEntry::create([
            'team_member_id' => $member->id,
            'type' => $type,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'hours' => $hours,
            'note' => $note,
        ]);

        $this->recomputer->recomputeIfEnabled();

        return $entry;
    }

    public function remove(CapacityEntry $entry): void
    {
        $entry->delete();

        $this->recomputer->recomputeIfEnabled();
    }
}
