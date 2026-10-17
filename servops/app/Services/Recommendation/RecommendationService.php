<?php

namespace App\Services\Recommendation;

use App\Data\Actor;
use App\Enums\RecommendationStatus;
use App\Enums\RequestStatus;
use App\Exceptions\WorkflowException;
use App\Models\Recommendation;
use App\Models\TeamMember;
use App\Services\Calendar\Clock;
use App\Services\Metrics\MetricsRecomputer;
use App\Services\Workflow\RequestChangeService;
use App\Services\Workflow\WorkflowTransitionService;

/**
 * Keputusan manusia atas rekomendasi. Rekomendasi penugasan dieksekusi lewat workflow
 * (dengan guard yang sama); tipe lain dicatat sebagai keputusan.
 */
class RecommendationService
{
    public function __construct(
        private Clock $clock,
        private WorkflowTransitionService $workflow,
        private RequestChangeService $changes,
        private MetricsRecomputer $recomputer,
    ) {}

    /**
     * @throws WorkflowException
     */
    public function accept(Recommendation $recommendation, Actor $actor): Recommendation
    {
        $this->assertOpen($recommendation);

        $payload = $recommendation->payload ?? [];
        $request = $recommendation->serviceRequest;
        $member = isset($payload['member_id']) ? TeamMember::query()->find($payload['member_id']) : null;
        $reason = "Rekomendasi diterima: {$recommendation->title}";

        match ($payload['action'] ?? null) {
            'assign' => $request->status === RequestStatus::Ready
                ? $this->workflow->transition($request, RequestStatus::Assigned, $actor, $reason, ['assignee_id' => $member->id])
                : $this->changes->reassign($request, $member, $reason, $actor),
            'reassign' => $this->changes->reassign($request, $member, $reason, $actor),
            default => null,
        };

        $recommendation->forceFill([
            'status' => RecommendationStatus::Accepted,
            'decided_by' => $actor->label(),
            'decided_at' => $this->clock->now(),
        ])->save();

        $this->recomputer->recomputeIfEnabled();

        return $recommendation->refresh();
    }

    public function dismiss(Recommendation $recommendation, Actor $actor, ?string $note = null): Recommendation
    {
        $this->assertOpen($recommendation);

        $recommendation->forceFill([
            'status' => RecommendationStatus::Dismissed,
            'decided_by' => $actor->label().($note ? " — {$note}" : ''),
            'decided_at' => $this->clock->now(),
        ])->save();

        return $recommendation;
    }

    private function assertOpen(Recommendation $recommendation): void
    {
        if ($recommendation->status !== RecommendationStatus::Open) {
            throw new WorkflowException('RECOMMENDATION_CLOSED', 'Rekomendasi ini sudah diputuskan.');
        }
    }
}
