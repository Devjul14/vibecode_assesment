<?php

namespace App\Services\Priority;

use App\Data\Actor;
use App\Enums\ActorRole;
use App\Exceptions\WorkflowException;
use App\Models\PriorityOverride;
use App\Models\ServiceRequest;
use App\Services\Calendar\Clock;
use App\Services\Metrics\MetricsRecomputer;
use App\Services\Recommendation\ImpactPreviewService;
use App\Services\Workflow\HistoryRecorder;

/**
 * Override prioritas oleh manajemen: wajib alasan, dapat di-preview dampaknya, dan tercatat di audit.
 */
class PriorityOverrideService
{
    public function __construct(
        private Clock $clock,
        private HistoryRecorder $history,
        private MetricsRecomputer $recomputer,
        private ImpactPreviewService $impact,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function preview(ServiceRequest $request, int $forcedRank, string $reason, Actor $actor): array
    {
        $this->assertAllowed($request, $reason, $actor);

        return $this->impact->preview(fn () => $this->createOverride($request, $forcedRank, $reason, $actor));
    }

    public function apply(ServiceRequest $request, int $forcedRank, string $reason, Actor $actor): PriorityOverride
    {
        $this->assertAllowed($request, $reason, $actor);

        $override = $this->createOverride($request, $forcedRank, $reason, $actor);
        $this->history->event($request, 'priority_overridden', $actor, $reason, ['old_rank' => $request->priority_rank, 'forced_rank' => $forcedRank]);
        $this->recomputer->recomputeIfEnabled();

        return $override;
    }

    public function revoke(PriorityOverride $override, Actor $actor, string $reason): void
    {
        if ($actor->role !== ActorRole::Manager) {
            throw new WorkflowException('OVERRIDE_ROLE', 'Hanya manager yang dapat mencabut override prioritas.');
        }

        $override->update(['revoked_at' => $this->clock->now()]);
        $this->history->event($override->serviceRequest, 'priority_override_revoked', $actor, $reason ?: 'Override dicabut');
        $this->recomputer->recomputeIfEnabled();
    }

    private function createOverride(ServiceRequest $request, int $forcedRank, string $reason, Actor $actor): PriorityOverride
    {
        PriorityOverride::query()
            ->where('service_request_id', $request->id)
            ->activeAt($this->clock->now())
            ->update(['revoked_at' => $this->clock->now()]);

        return PriorityOverride::create([
            'service_request_id' => $request->id,
            'old_rank' => $request->priority_rank,
            'forced_rank' => max(1, $forcedRank),
            'pinned' => true,
            'reason' => $reason,
            'approved_by' => $actor->label(),
        ]);
    }

    private function assertAllowed(ServiceRequest $request, string $reason, Actor $actor): void
    {
        if ($actor->role !== ActorRole::Manager) {
            throw new WorkflowException('OVERRIDE_ROLE', 'Hanya manager yang dapat meng-override prioritas.');
        }

        if (blank($reason)) {
            throw WorkflowException::reasonRequired('override prioritas');
        }

        if (! $request->status->isOpen()) {
            throw WorkflowException::requestClosed();
        }
    }
}
