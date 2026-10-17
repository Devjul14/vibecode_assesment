<?php

namespace Tests\Feature;

use App\Models\PriorityOverride;
use App\Models\ServiceRequest;
use App\Services\Metrics\MetricsRecomputer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSeededScenario;
use Tests\TestCase;

class PriorityEngineTest extends TestCase
{
    use RefreshDatabase, UsesSeededScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedScenario();
    }

    public function test_incident_outranks_requests_labelled_high_by_requester(): void
    {
        $this->assertSame(1, $this->sr('SR-102')->priority_rank);
        $this->assertGreaterThan($this->sr('SR-101')->priority_score, $this->sr('SR-102')->priority_score);
        $this->assertSame('high', $this->sr('SR-101')->requester_priority->value);
    }

    public function test_breakdown_explains_the_score(): void
    {
        $request = $this->sr('SR-102');
        $breakdown = collect($request->priority_breakdown);

        $this->assertEqualsWithDelta($request->priority_score, $breakdown->sum('contribution'), 0.05);
        $this->assertSame(['impact', 'urgency', 'dependency', 'requester', 'scarcity', 'age'], $breakdown->pluck('factor')->all());
        $this->assertTrue($breakdown->every(fn (array $factor) => filled($factor['note'])));
        $this->assertStringContainsString('3 departemen', $breakdown->firstWhere('factor', 'impact')['note']);
    }

    public function test_requester_priority_carries_small_weight(): void
    {
        $requester = collect($this->sr('SR-101')->priority_breakdown)->firstWhere('factor', 'requester');

        $this->assertSame(100.0, (float) $requester['raw']);
        $this->assertLessThanOrEqual(10.0, (float) $requester['contribution']);
    }

    public function test_unknown_impact_is_scored_as_medium_and_flagged_for_review(): void
    {
        $impact = collect($this->sr('SR-107')->priority_breakdown)->firstWhere('factor', 'impact');

        $this->assertGreaterThanOrEqual(50.0, (float) $impact['raw']);
        $this->assertContains('IMPACT_UNKNOWN', $this->reviewCodes('SR-107'));
        $this->assertTrue($this->sr('SR-107')->needs_human_review);
    }

    public function test_unknown_estimate_uses_historical_p80_and_requires_review(): void
    {
        $this->assertContains('ESTIMATE_UNKNOWN', $this->reviewCodes('SR-106'));
        $this->assertStringContainsString('P80', collect($this->sr('SR-106')->review_reasons)->firstWhere('code', 'ESTIMATE_UNKNOWN')['message']);
    }

    public function test_pinned_override_goes_first_but_keeps_original_score_and_review_flag(): void
    {
        $sr107 = $this->sr('SR-107');
        $score = $sr107->priority_score;

        PriorityOverride::create([
            'service_request_id' => $sr107->id,
            'old_rank' => $sr107->priority_rank,
            'forced_rank' => 1,
            'pinned' => true,
            'reason' => 'Selisih data berdampak ke laporan regulator',
            'approved_by' => 'Manager Operasional',
        ]);
        app(MetricsRecomputer::class)->run();

        $this->assertSame(1, $this->sr('SR-107')->priority_rank);
        $this->assertSame(2, $this->sr('SR-102')->priority_rank);
        $this->assertSame($score, $this->sr('SR-107')->priority_score);
        $this->assertContains('OVERRIDE_ACTIVE', $this->reviewCodes('SR-107'));
    }

    public function test_close_scores_on_the_same_skill_are_flagged(): void
    {
        $flagged = ServiceRequest::query()->whereNotNull('review_reasons')->get()
            ->filter(fn (ServiceRequest $request) => in_array('CLOSE_CALL', array_column($request->review_reasons, 'code'), true));

        $this->assertNotEmpty($flagged);
        $this->assertSame(1, $flagged->pluck('required_skill_id')->unique()->count());
    }

    public function test_closed_requests_are_not_ranked(): void
    {
        $this->assertSame(0, ServiceRequest::done()->whereNotNull('priority_rank')->count());
        $this->assertSame(11, ServiceRequest::query()->whereNotNull('priority_rank')->count());
    }
}
