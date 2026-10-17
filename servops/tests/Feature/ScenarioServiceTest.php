<?php

namespace Tests\Feature;

use App\Data\Actor;
use App\Enums\ActorRole;
use App\Enums\RecommendationType;
use App\Enums\RequestStatus;
use App\Enums\RiskLevel;
use App\Enums\Scenario;
use App\Exceptions\WorkflowException;
use App\Models\CapacityEntry;
use App\Models\PriorityOverride;
use App\Models\ScenarioRun;
use App\Models\ServiceRequest;
use App\Services\Calendar\Clock;
use App\Services\Simulation\ScenarioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\UsesSeededScenario;
use Tests\TestCase;

class ScenarioServiceTest extends TestCase
{
    use RefreshDatabase, UsesSeededScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedScenario();
        config(['servops.now' => null]); // jam dikendalikan oleh Clock (dibekukan seeder) agar bisa dimajukan
    }

    private function scenarios(): ScenarioService
    {
        return app(ScenarioService::class);
    }

    private function manager(): Actor
    {
        return new Actor(ActorRole::Manager, 'Sari Wulandari');
    }

    private function lead(): Actor
    {
        return new Actor(ActorRole::TeamLead, 'Rudi Hartono');
    }

    /** @return array<string, mixed>|null */
    private function changed(ScenarioRun $run, string $code): ?array
    {
        return collect($this->scenarios()->diff($run->before, $run->after)['requests'])->firstWhere('code', $code);
    }

    public function test_s1_low_priority_with_wide_impact_rises_in_rank(): void
    {
        $rankBefore = $this->sr('SR-107')->priority_rank;

        $run = $this->scenarios()->run(Scenario::ImpactEscalation, [], $this->lead());

        $this->assertLessThan($rankBefore, $this->sr('SR-107')->priority_rank);
        $this->assertNotContains('IMPACT_UNKNOWN', $this->reviewCodes('SR-107'));
        $this->assertNotContains(RecommendationType::TriageImpact->value, $this->openRecommendationTypes('SR-107'));
        $this->assertNotNull($this->changed($run, 'SR-107'));
        $this->assertSame('impact_assessed', $this->sr('SR-107')->statusHistories->last()->meta['event']);
    }

    public function test_s2_unavailable_member_triggers_risk_and_reassignment(): void
    {
        $run = $this->scenarios()->run(Scenario::MemberUnavailable, ['member_id' => $this->teamMember('Andi')->id, 'days' => 3], $this->lead());

        $this->assertContains('ASSIGNEE_UNAVAILABLE', $this->riskCodes('SR-095'));
        $this->assertContains(RecommendationType::Reassign->value, $this->openRecommendationTypes('SR-095'));
        $this->assertSame(1, CapacityEntry::query()->where('team_member_id', $this->teamMember('Andi')->id)->count());
        $this->assertNotEmpty($this->scenarios()->diff($run->before, $run->after)['new_recommendations']);
    }

    public function test_s3_returned_delivery_is_not_counted_done_and_raises_rework(): void
    {
        $run = $this->scenarios()->run(Scenario::DeliveryReturned, [], $this->lead());
        $diff = $this->scenarios()->diff($run->before, $run->after);

        $sr101 = $this->sr('SR-101');
        $this->assertSame(RequestStatus::Returned, $sr101->status);
        $this->assertSame(1, $sr101->rework_count);
        $this->assertEquals(0, $diff['kpis']['done']['delta']);
        $this->assertGreaterThan(0, $diff['kpis']['rework_rate']['delta']);
        $this->assertTrue($sr101->status->countsAsLoad());
    }

    public function test_s3_rejects_closed_request(): void
    {
        $this->expectException(WorkflowException::class);

        $this->scenarios()->run(Scenario::DeliveryReturned, ['code' => 'SR-001'], $this->lead());
    }

    public function test_s4_dependency_delay_and_time_passing_worsens_sr105(): void
    {
        $this->assertSame(RiskLevel::AtRisk, $this->sr('SR-105')->risk_level);

        $run = $this->scenarios()->run(Scenario::DependencyDelay, [], $this->lead());

        $this->assertSame(RiskLevel::WillBreach, $this->sr('SR-105')->risk_level);
        $this->assertContains('EXTERNAL_ETA_LATE', $this->riskCodes('SR-105'));
        $this->assertContains(RecommendationType::EscalateDependency->value, $this->openRecommendationTypes('SR-105'));
        $this->assertNotSame($run->before['at'], $run->after['at']);
        $this->assertTrue($this->changed($run, 'SR-105')['worse']);
    }

    public function test_s5_urgent_insert_requires_manager_and_shows_displaced_work(): void
    {
        try {
            $this->scenarios()->run(Scenario::UrgentInsert, [], $this->lead());
            $this->fail('Team lead tidak boleh menjalankan S5');
        } catch (WorkflowException $exception) {
            $this->assertSame('SCENARIO_ROLE', $exception->rule);
        }

        $run = $this->scenarios()->run(Scenario::UrgentInsert, [], $this->manager());
        $diff = $this->scenarios()->diff($run->before, $run->after);
        $new = collect($diff['requests'])->firstWhere('is_new', true);

        $this->assertSame(1, $new['rank_after']);
        $this->assertSame(1, PriorityOverride::query()->count());
        $this->assertGreaterThan(1, count($diff['requests']), 'Request lain harus bergeser');
    }

    public function test_s6_estimate_growth_flags_review_and_risk(): void
    {
        $finishBefore = $this->sr('SR-103')->projected_finish_at;

        $this->scenarios()->run(Scenario::EstimateGrowth, [], $this->lead());

        $this->assertContains('ESTIMATE_GROWTH', $this->riskCodes('SR-103'));
        $this->assertContains('ESTIMATE_GROWTH', $this->reviewCodes('SR-103'));
        $this->assertTrue($this->sr('SR-103')->projected_finish_at->greaterThan($finishBefore));
    }

    public function test_preview_changes_nothing_including_clock(): void
    {
        $clockBefore = app(Clock::class)->now()->toIso8601String();
        $requests = ServiceRequest::query()->count();

        $diff = $this->scenarios()->preview(Scenario::DependencyDelay, [], $this->lead());

        $this->assertNotEmpty($diff['requests']);
        $this->assertSame($clockBefore, app(Clock::class)->now()->toIso8601String());
        $this->assertSame(RiskLevel::AtRisk, $this->sr('SR-105')->risk_level);
        $this->assertSame(0, ScenarioRun::query()->count());
        $this->assertSame($requests, ServiceRequest::query()->count());
    }

    public function test_clock_advance_is_recorded_and_eventually_breaches(): void
    {
        $run = $this->scenarios()->advanceClock(8, $this->lead());

        $this->assertSame(Scenario::ClockAdvance, $run->scenarioType());
        $this->assertSame('2026-10-20 09:00', app(Clock::class)->now()->format('Y-m-d H:i'));
        $this->assertSame(RiskLevel::Breached, $this->sr('SR-102')->risk_level);
    }

    public function test_reset_is_guarded_by_config(): void
    {
        config(['servops.allow_reset' => false]);

        try {
            $this->scenarios()->reset();
            $this->fail('Reset harus ditolak');
        } catch (WorkflowException $exception) {
            $this->assertSame('RESET_DISABLED', $exception->rule);
        }

        config(['servops.allow_reset' => true]);
        Artisan::shouldReceive('call')->once()->with('migrate:fresh', ['--seed' => true, '--force' => true]);

        $this->scenarios()->reset();
    }
}
