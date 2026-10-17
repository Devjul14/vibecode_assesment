<?php

namespace Tests\Feature;

use App\Data\Actor;
use App\Enums\ActorRole;
use App\Enums\CapacityEntryType;
use App\Enums\RecommendationStatus;
use App\Enums\RecommendationType;
use App\Enums\RequestStatus;
use App\Exceptions\WorkflowException;
use App\Models\CapacityEntry;
use App\Models\Recommendation;
use App\Services\Metrics\MetricsRecomputer;
use App\Services\Recommendation\RecommendationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSeededScenario;
use Tests\TestCase;

class RecommendationTest extends TestCase
{
    use RefreshDatabase, UsesSeededScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedScenario();
    }

    private function manager(): Actor
    {
        return new Actor(ActorRole::Manager, 'Manager');
    }

    private function recommendationFor(string $code, RecommendationType $type): Recommendation
    {
        return Recommendation::query()->open()
            ->where('service_request_id', $this->sr($code)->id)
            ->where('type', $type)
            ->firstOrFail();
    }

    public function test_recompute_is_idempotent(): void
    {
        $before = Recommendation::query()->count();

        $stats = app(MetricsRecomputer::class)->run()['recommendations'];

        $this->assertSame(0, $stats['created']);
        $this->assertSame(0, $stats['closed']);
        $this->assertSame($before, Recommendation::query()->count());
    }

    public function test_accepting_assign_recommendation_runs_the_workflow(): void
    {
        $recommendation = $this->recommendationFor('SR-102', RecommendationType::Reassign);

        app(RecommendationService::class)->accept($recommendation, $this->manager());

        $this->assertSame(RequestStatus::Assigned, $this->sr('SR-102')->status);
        $this->assertSame('Budi', $this->sr('SR-102')->assignee->name);
        $this->assertSame(RecommendationStatus::Accepted, $recommendation->fresh()->status);
        $this->assertSame('Manager (Manager)', $recommendation->fresh()->decided_by);
        $this->assertNotContains('UNASSIGNED_LOW_SLACK', $this->riskCodes('SR-102'));
    }

    public function test_recommendation_can_only_be_decided_once(): void
    {
        $recommendation = $this->recommendationFor('SR-107', RecommendationType::TriageImpact);
        $service = app(RecommendationService::class);

        $service->dismiss($recommendation, $this->manager(), 'Sudah ditriage via telepon');

        $this->expectException(WorkflowException::class);
        $service->accept($recommendation->fresh(), $this->manager());
    }

    public function test_dismissed_recommendation_is_not_reopened_by_recompute(): void
    {
        $recommendation = $this->recommendationFor('SR-107', RecommendationType::TriageImpact);
        app(RecommendationService::class)->dismiss($recommendation, $this->manager());

        app(MetricsRecomputer::class)->run();

        $this->assertSame(RecommendationStatus::Dismissed, $recommendation->fresh()->status);
        $this->assertSame(1, Recommendation::query()->where('fingerprint', $recommendation->fingerprint)->count());
    }

    public function test_stale_recommendation_is_closed_when_condition_disappears(): void
    {
        $recommendation = $this->recommendationFor('SR-107', RecommendationType::TriageImpact);
        $this->sr('SR-107')->update(['impact_level' => 'high']);

        app(MetricsRecomputer::class)->run();

        $this->assertSame(RecommendationStatus::Dismissed, $recommendation->fresh()->status);
        $this->assertStringContainsString('tidak berlaku', $recommendation->fresh()->decided_by);
    }

    public function test_unavailable_assignee_triggers_reassign_to_qualified_member(): void
    {
        CapacityEntry::create([
            'team_member_id' => $this->teamMember('Andi')->id,
            'type' => CapacityEntryType::Sick,
            'starts_at' => '2026-10-19 00:00',
            'ends_at' => '2026-10-21 23:59',
        ]);
        app(MetricsRecomputer::class)->run();

        $recommendation = $this->recommendationFor('SR-095', RecommendationType::Reassign);
        $this->assertSame('Citra', $recommendation->member->name);
        $this->assertSame(3, $recommendation->severity);

        app(RecommendationService::class)->accept($recommendation, $this->manager());
        $this->assertSame('Citra', $this->sr('SR-095')->assignee->name);
    }

    public function test_scarce_expert_absence_without_backup_suggests_negotiation(): void
    {
        $this->sr('SR-103')->update(['assignee_id' => $this->teamMember('Citra')->id, 'status' => RequestStatus::Assigned]);
        CapacityEntry::create([
            'team_member_id' => $this->teamMember('Citra')->id,
            'type' => CapacityEntryType::Sick,
            'starts_at' => '2026-10-19 00:00',
            'ends_at' => '2026-10-23 23:59',
        ]);
        app(MetricsRecomputer::class)->run();

        $types = $this->openRecommendationTypes('SR-103');

        $this->assertNotContains(RecommendationType::Reassign->value, $types);
        $this->assertTrue(
            in_array(RecommendationType::NegotiateDeadline->value, $types, true) || in_array(RecommendationType::Pairing->value, $types, true),
            'Harus ada opsi negosiasi tenggat atau pairing: '.implode(', ', $types),
        );
    }
}
