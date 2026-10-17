<?php

namespace App\Data;

/**
 * Skor prioritas yang dapat dijelaskan: kontribusi tiap faktor + pemicu review manusia.
 */
final class PriorityResult
{
    /**
     * @param  list<array{factor: string, label: string, raw: float, weight: float, contribution: float, note: string}>  $breakdown
     * @param  list<Reason>  $reviewReasons
     */
    public function __construct(
        public readonly int $requestId,
        public readonly float $score,
        public readonly array $breakdown,
        public array $reviewReasons = [],
        public ?int $rank = null,
        public readonly ?int $pinnedRank = null,
    ) {}

    public function needsHumanReview(): bool
    {
        return $this->reviewReasons !== [];
    }

    public function addReviewReason(Reason $reason): void
    {
        foreach ($this->reviewReasons as $existing) {
            if ($existing->code === $reason->code) {
                return;
            }
        }

        $this->reviewReasons[] = $reason;
    }

    public function isPinned(): bool
    {
        return $this->pinnedRank !== null;
    }
}
