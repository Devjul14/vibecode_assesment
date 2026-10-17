<?php

namespace App\Data;

use App\Enums\RiskLevel;
use Carbon\CarbonImmutable;

/**
 * Status risiko SLA beserta alasan & tindakan, dihitung dari proyeksi antrean (bukan hanya slack).
 */
final readonly class RiskResult
{
    /**
     * @param  list<Reason>  $reasons
     */
    public function __construct(
        public int $requestId,
        public RiskLevel $level,
        public array $reasons,
        public ?float $slackHours,
        public ?CarbonImmutable $latestStartAt,
        public ?CarbonImmutable $projectedFinishAt,
        public float $plannedHours,
        public ?int $projectedAssigneeId = null,
    ) {}

    public function hasReason(string $code): bool
    {
        foreach ($this->reasons as $reason) {
            if ($reason->code === $code) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public function reasonCodes(): array
    {
        return array_map(fn (Reason $reason): string => $reason->code, $this->reasons);
    }
}
