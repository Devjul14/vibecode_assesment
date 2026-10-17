<?php

namespace Tests\Feature;

use App\Data\Actor;
use App\Enums\ActorRole;
use App\Enums\RecommendationType;
use App\Enums\RequestStatus;
use App\Enums\RiskLevel;
use App\Exceptions\WorkflowException;
use App\Models\Recommendation;
use App\Models\ServiceRequest;
use App\Services\Workflow\WorkflowTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSeededScenario;
use Tests\TestCase;

/**
 * Acceptance criteria prototipe — BLUEPRINT §14.3, dijalankan pada data awal soal.
 */
class BlueprintAcceptanceTest extends TestCase
{
    use RefreshDatabase, UsesSeededScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedScenario();
    }

    public function test_1_sr102_is_rank_one_despite_medium_requester_priority(): void
    {
        $this->assertSame(1, $this->sr('SR-102')->priority_rank);
        $this->assertSame('medium', $this->sr('SR-102')->requester_priority->value);
    }

    public function test_2_sr104_is_rework_load_and_not_done(): void
    {
        $sr104 = $this->sr('SR-104');

        $this->assertTrue($sr104->isRework());
        $this->assertTrue($sr104->status->countsAsLoad());
        $this->assertFalse(ServiceRequest::done()->whereKey($sr104->id)->exists());
        $this->assertSame(4.0, $sr104->remaining_hours);
    }

    public function test_3_sr105_at_risk_with_escalation(): void
    {
        $this->assertSame(RiskLevel::AtRisk, $this->sr('SR-105')->risk_level);
        $this->assertContains(RecommendationType::EscalateDependency->value, $this->openRecommendationTypes('SR-105'));
    }

    public function test_4_sr106_cannot_become_ready_and_needs_clarification(): void
    {
        $workflow = app(WorkflowTransitionService::class);
        $lead = new Actor(ActorRole::TeamLead, 'Lead');
        $workflow->transition($this->sr('SR-106'), RequestStatus::Triage, $lead, 'Workshop dijadwalkan');

        try {
            $workflow->transition($this->sr('SR-106'), RequestStatus::Ready, $lead);
            $this->fail('SR-106 tidak boleh siap tanpa estimasi');
        } catch (WorkflowException $exception) {
            $this->assertSame('ESTIMATE_REQUIRED', $exception->rule);
        }

        $this->assertContains(RecommendationType::ClarifyScope->value, $this->openRecommendationTypes('SR-106'));
    }

    public function test_5_sr107_needs_review_and_triage_not_treated_as_low(): void
    {
        $impact = collect($this->sr('SR-107')->priority_breakdown)->firstWhere('factor', 'impact');

        $this->assertTrue($this->sr('SR-107')->needs_human_review);
        $this->assertContains('IMPACT_UNKNOWN', $this->reviewCodes('SR-107'));
        $this->assertGreaterThan(25, (float) $impact['raw']);
        $this->assertContains(RecommendationType::TriageImpact->value, $this->openRecommendationTypes('SR-107'));
    }

    public function test_6_sr103_scarce_skill_assign_to_citra_early(): void
    {
        $this->assertContains('SCARCE_SKILL', $this->riskCodes('SR-103'));

        $assign = Recommendation::query()->open()
            ->where('service_request_id', $this->sr('SR-103')->id)
            ->where('type', RecommendationType::Reassign)
            ->sole();

        $this->assertSame('Citra', $assign->member->name);
        $this->assertGreaterThanOrEqual(2, $assign->severity);
    }

    public function test_7_sr101_to_dewi_ok_but_sr107_to_dewi_rejected_without_pairing(): void
    {
        $workflow = app(WorkflowTransitionService::class);
        $lead = new Actor(ActorRole::TeamLead, 'Lead');
        $dewi = $this->teamMember('Dewi');

        $result = $workflow->transition($this->sr('SR-101'), RequestStatus::Assigned, $lead, payload: ['assignee_id' => $dewi->id]);
        $this->assertSame('Dewi', $result->request->assignee->name);

        $workflow->transition($this->sr('SR-107'), RequestStatus::Ready, $lead);

        try {
            $workflow->transition($this->sr('SR-107'), RequestStatus::Assigned, $lead, payload: ['assignee_id' => $dewi->id]);
            $this->fail('Dewi tidak boleh ditugaskan SR-107 tanpa pairing');
        } catch (WorkflowException $exception) {
            $this->assertSame('UNDERQUALIFIED', $exception->rule);
        }
    }
}
