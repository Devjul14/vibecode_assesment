<?php

namespace Tests\Feature;

use App\Data\Actor;
use App\Enums\ActorRole;
use App\Enums\CapacityEntryType;
use App\Enums\RiskLevel;
use App\Models\CapacityEntry;
use App\Services\Calendar\Clock;
use App\Services\Metrics\MetricsRecomputer;
use App\Services\Workflow\RequestChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSeededScenario;
use Tests\TestCase;

class SlaRiskEngineTest extends TestCase
{
    use RefreshDatabase, UsesSeededScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedScenario();
    }

    private function recompute(): void
    {
        app(MetricsRecomputer::class)->run();
    }

    public function test_risk_separates_deadline_estimate_and_projection(): void
    {
        $sr101 = $this->sr('SR-101');

        $this->assertSame(RiskLevel::OnTrack, $sr101->risk_level);
        $this->assertNotNull($sr101->latest_start_at);
        $this->assertNotNull($sr101->projected_finish_at);
        $this->assertTrue($sr101->projected_finish_at->lessThan($sr101->deadline_at));
        $this->assertSame(17.0, $sr101->slack_hours);
    }

    public function test_blocked_request_waiting_on_finance_is_at_risk_before_deadline(): void
    {
        $sr105 = $this->sr('SR-105');

        $this->assertSame(RiskLevel::AtRisk, $sr105->risk_level);
        $this->assertContains('DEPENDENCY_WAIT', $this->riskCodes('SR-105'));
        $this->assertTrue($sr105->projected_finish_at->greaterThan($this->sr('SR-105')->openDependencies()->first()->expected_at));
    }

    public function test_finance_delay_turns_sr105_into_will_breach(): void
    {
        $this->sr('SR-105')->openDependencies()->first()->update(['expected_at' => '2026-10-21 14:00']);
        $this->recompute();

        $this->assertSame(RiskLevel::WillBreach, $this->sr('SR-105')->risk_level);
        $this->assertContains('EXTERNAL_ETA_LATE', $this->riskCodes('SR-105'));
    }

    public function test_unclear_scope_is_at_risk(): void
    {
        $this->assertSame(RiskLevel::AtRisk, $this->sr('SR-106')->risk_level);
        $this->assertContains('SCOPE_UNCLEAR', $this->riskCodes('SR-106'));
    }

    public function test_scarce_skill_is_flagged_for_single_busy_expert(): void
    {
        $this->assertContains('SCARCE_SKILL', $this->riskCodes('SR-103'));
        $this->assertNotContains('SCARCE_SKILL', $this->riskCodes('SR-101'));
    }

    public function test_assignee_unavailable_escalates_risk(): void
    {
        CapacityEntry::create([
            'team_member_id' => $this->teamMember('Andi')->id,
            'type' => CapacityEntryType::Sick,
            'starts_at' => '2026-10-19 00:00',
            'ends_at' => '2026-10-20 23:59',
        ]);
        $this->recompute();

        $this->assertContains('ASSIGNEE_UNAVAILABLE', $this->riskCodes('SR-095'));
        $this->assertNotSame(RiskLevel::OnTrack, $this->sr('SR-095')->risk_level);
    }

    public function test_scope_growth_flags_risk_but_rework_revision_does_not(): void
    {
        $this->assertNotContains('ESTIMATE_GROWTH', $this->riskCodes('SR-104'), 'Revisi rework 5→9 jam bukan pembengkakan scope');

        app(RequestChangeService::class)->reviseEstimate($this->sr('SR-097'), 24, 'Scope API vendor bertambah', new Actor(ActorRole::Member, 'Citra'), scopeChange: true);

        $this->assertContains('ESTIMATE_GROWTH', $this->riskCodes('SR-097'));
        $this->assertContains('ESTIMATE_GROWTH', $this->reviewCodes('SR-097'));
    }

    public function test_passing_deadline_marks_breached(): void
    {
        app(Clock::class)->advance(24 * 3);
        $this->recompute();

        $this->assertSame(RiskLevel::Breached, $this->sr('SR-102')->risk_level);
    }

    public function test_rework_is_visible_as_risk_context(): void
    {
        $this->assertContains('REWORK', $this->riskCodes('SR-104'));
    }
}
