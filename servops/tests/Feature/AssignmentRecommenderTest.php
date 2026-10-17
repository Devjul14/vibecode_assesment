<?php

namespace Tests\Feature;

use App\Data\AssignmentCandidate;
use App\Enums\CapacityEntryType;
use App\Models\CapacityEntry;
use App\Services\Assignment\AssignmentRecommender;
use App\Services\Capacity\CapacityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSeededScenario;
use Tests\TestCase;

class AssignmentRecommenderTest extends TestCase
{
    use RefreshDatabase, UsesSeededScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedScenario();
    }

    private function recommender(): AssignmentRecommender
    {
        app(CapacityService::class)->flush();
        $recommender = app(AssignmentRecommender::class);
        $recommender->flush();

        return $recommender;
    }

    public function test_only_qualified_members_are_candidates(): void
    {
        $names = $this->recommender()->candidates($this->sr('SR-103'))
            ->map(fn (AssignmentCandidate $candidate) => $candidate->member->name)->all();

        $this->assertSame(['Citra'], $names);
    }

    public function test_free_member_with_exact_level_is_preferred_over_busy_expert(): void
    {
        $candidates = $this->recommender()->candidates($this->sr('SR-101'));
        $best = $candidates->first();

        $this->assertSame('Dewi', $best->member->name);
        $this->assertTrue($best->finishesBeforeDeadline);
        $this->assertContains('Andi', $candidates->map(fn (AssignmentCandidate $c) => $c->member->name)->all());
        $this->assertNotEmpty($candidates->firstWhere('member.name', 'Andi')->warnings, 'Andi overqualified');
    }

    public function test_underqualified_member_with_capacity_is_only_a_pairing_option(): void
    {
        $recommender = $this->recommender();
        $sr107 = $this->sr('SR-107');

        $this->assertNotContains('Dewi', $recommender->candidates($sr107)->map(fn (AssignmentCandidate $c) => $c->member->name)->all());

        $pairing = $recommender->pairingOptions($sr107)->first();
        $this->assertSame('Dewi', $pairing->member->name);
        $this->assertFalse($pairing->qualified);
        $this->assertNotNull($recommender->mentorFor($sr107, $pairing->member->id));
    }

    public function test_absent_member_is_excluded(): void
    {
        CapacityEntry::create([
            'team_member_id' => $this->teamMember('Citra')->id,
            'type' => CapacityEntryType::Sick,
            'starts_at' => '2026-10-19 00:00',
            'ends_at' => '2026-10-21 23:59',
        ]);

        $this->assertTrue($this->recommender()->candidates($this->sr('SR-103'))->isEmpty());
    }

    public function test_candidates_explain_their_fit(): void
    {
        $best = $this->recommender()->candidates($this->sr('SR-102'))->first();

        $this->assertSame('Budi', $best->member->name);
        $this->assertStringContainsString('Level 3', $best->reasons[0]);
        $this->assertNotNull($best->projectedFinishAt);
    }
}
