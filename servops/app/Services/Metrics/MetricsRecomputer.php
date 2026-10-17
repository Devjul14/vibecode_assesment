<?php

namespace App\Services\Metrics;

use App\Data\PriorityResult;
use App\Data\Reason;
use App\Data\RiskResult;
use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Services\Assignment\AssignmentRecommender;
use App\Services\Calendar\Clock;
use App\Services\Capacity\CapacityService;
use App\Services\Estimation\EstimateAdvisor;
use App\Services\Priority\PriorityEngine;
use App\Services\Recommendation\RecommendationEngine;
use App\Services\Risk\SlaRiskEngine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Orkestrator perhitungan: Priority → Risk (butuh ranking) → Recommendation.
 * Hasil disimpan ter-denormalisasi di service_requests agar dashboard cepat dan alasan dapat diaudit.
 */
class MetricsRecomputer
{
    private bool $running = false;

    public function __construct(
        private Clock $clock,
        private PriorityEngine $priority,
        private SlaRiskEngine $risk,
        private RecommendationEngine $recommendations,
        private CapacityService $capacity,
        private AssignmentRecommender $assignments,
        private EstimateAdvisor $estimates,
    ) {}

    /** Dipanggil service workflow setelah perubahan; patuh pada config servops.auto_recompute. */
    public function recomputeIfEnabled(): void
    {
        if (config('servops.auto_recompute')) {
            $this->run();
        }
    }

    /**
     * @return array{requests: int, at_risk: int, needs_review: int, recommendations: array{created: int, updated: int, closed: int}}
     */
    public function run(): array
    {
        if ($this->running) {
            return ['requests' => 0, 'at_risk' => 0, 'needs_review' => 0, 'recommendations' => ['created' => 0, 'updated' => 0, 'closed' => 0]];
        }

        $this->running = true;

        try {
            $this->flushCaches();
            $requests = $this->openRequests();
            $priorities = $this->priority->evaluateAll($requests);
            $ranks = array_map(fn (PriorityResult $result) => $result->rank, $priorities);
            $risks = $this->risk->evaluateAll($requests, $ranks);

            DB::transaction(function () use ($requests, $priorities, $risks): void {
                $this->persist($requests, $priorities, $risks);
                $this->clearClosed();
            });

            $recommendationStats = $this->recommendations->sync(
                $this->recommendations->generate($requests, $priorities, $risks)
            );

            return [
                'requests' => $requests->count(),
                'at_risk' => collect($risks)->filter(fn (RiskResult $risk) => $risk->level->severity() > 0)->count(),
                'needs_review' => collect($priorities)->filter(fn (PriorityResult $result) => $result->needsHumanReview())->count(),
                'recommendations' => $recommendationStats,
            ];
        } finally {
            $this->running = false;
        }
    }

    /** @return Collection<int, ServiceRequest> */
    private function openRequests(): Collection
    {
        return ServiceRequest::query()
            ->open()
            ->where('status', '!=', RequestStatus::New)
            ->with(['assignee.skills', 'requiredSkill', 'businessProcess', 'estimateRevisions', 'openDependencies.dependsOn', 'parent'])
            ->get();
    }

    /**
     * @param  Collection<int, ServiceRequest>  $requests
     * @param  array<int, PriorityResult>  $priorities
     * @param  array<int, RiskResult>  $risks
     */
    private function persist(Collection $requests, array $priorities, array $risks): void
    {
        $now = $this->clock->now();

        foreach ($requests as $request) {
            $priority = $priorities[$request->id];
            $risk = $risks[$request->id];

            $request->forceFill([
                'priority_score' => $priority->score,
                'priority_breakdown' => $priority->breakdown,
                'priority_rank' => $priority->rank,
                'needs_human_review' => $priority->needsHumanReview(),
                'review_reasons' => Reason::toArrayList($priority->reviewReasons),
                'risk_level' => $risk->level,
                'risk_reasons' => Reason::toArrayList($risk->reasons),
                'slack_hours' => $risk->slackHours,
                'latest_start_at' => $risk->latestStartAt,
                'projected_finish_at' => $risk->projectedFinishAt,
                'metrics_computed_at' => $now,
            ]);

            $request->timestamps = false;
            $request->save();
        }
    }

    /** Request yang sudah ditutup tidak lagi diranking. */
    private function clearClosed(): void
    {
        ServiceRequest::query()
            ->whereNotIn('status', RequestStatus::openStatuses())
            ->whereNotNull('priority_rank')
            ->update(['priority_rank' => null]);
    }

    /** Kosongkan memo engine (mis. setelah preview yang di-rollback). */
    public function flushCaches(): void
    {
        $this->capacity->flush();
        $this->assignments->flush();
        $this->estimates->flush();
        $this->risk->flush();
    }
}
