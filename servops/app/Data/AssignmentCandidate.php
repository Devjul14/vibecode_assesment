<?php

namespace App\Data;

use App\Models\TeamMember;
use Carbon\CarbonImmutable;

/**
 * Kandidat penugasan dengan skor kecocokan dan alasan yang dapat ditampilkan.
 */
final readonly class AssignmentCandidate
{
    /**
     * @param  list<string>  $reasons
     * @param  list<string>  $warnings
     */
    public function __construct(
        public TeamMember $member,
        public bool $qualified,
        public int $skillLevel,
        public float $fit,
        public float $availableHours,
        public float $queueAheadHours,
        public ?CarbonImmutable $projectedFinishAt,
        public bool $finishesBeforeDeadline,
        public array $reasons = [],
        public array $warnings = [],
    ) {}
}
