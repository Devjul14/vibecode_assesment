<?php

namespace Tests\Feature;

use App\Enums\ImpactLevel;
use App\Enums\RequestStatus as S;
use App\Exceptions\WorkflowException;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\StatusHistory;
use App\Models\TeamMember;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithWorkflow;
use Tests\TestCase;

/**
 * Kasus batas workflow Fase 3 (C3.3–C3.6) di luar alur bahagia.
 */
class Phase3EdgeCaseTest extends TestCase
{
    use InteractsWithWorkflow, RefreshDatabase;

    private Skill $skill;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

        config(['servops.auto_recompute' => false]);
        $this->now = $this->freezeClock();
        $this->skill = Skill::factory()->create();
    }

    private function memberWithLevel(int $level, array $attributes = []): TeamMember
    {
        $member = TeamMember::factory()->create($attributes);
        $member->skills()->attach($this->skill, ['level' => $level]);

        return $member;
    }

    private function request(S $status, array $attributes = []): ServiceRequest
    {
        return ServiceRequest::factory()->status($status)->create([
            'required_skill_id' => $this->skill->id,
            'required_level' => 2,
            ...$attributes,
        ]);
    }

    private function assertRejected(callable $callback): void
    {
        try {
            $callback();
        } catch (WorkflowException) {
            $this->addToAssertionCount(1);

            return;
        }

        $this->fail('Diharapkan WorkflowException, tetapi aksi diterima.');
    }

    public function test_ready_with_unknown_impact_flags_human_review(): void
    {
        config(['servops.auto_recompute' => true]);
        $request = $this->request(S::Triage, ['impact_level' => ImpactLevel::Unknown]);

        $ready = $this->workflow()->transition($request, S::Ready, $this->lead())->request;

        $this->assertTrue($ready->needs_human_review);
    }

    public function test_failed_guard_leaves_no_partial_writes(): void
    {
        $request = $this->request(S::Delivered, ['rework_count' => 0]);

        $this->assertRejected(fn () => $this->workflow()->transition($request, S::Returned, $this->requester(), 'Salah', ['rework_estimate_hours' => 3]));

        $fresh = $request->fresh();
        $this->assertSame(S::Delivered, $fresh->status);
        $this->assertSame(0, $fresh->rework_count);
        $this->assertSame(0, StatusHistory::count());
        $this->assertSame(0, $fresh->estimateRevisions()->count());
    }

    public function test_cancel_from_blocked_keeps_audit_and_is_not_done(): void
    {
        $request = $this->request(S::Blocked, ['blocked_since' => $this->now->subHours(2)]);

        $cancelled = $this->workflow()->transition($request, S::Cancelled, $this->lead(), 'Tidak dibutuhkan lagi')->request;

        $this->assertSame(S::Cancelled, $cancelled->status);
        $this->assertFalse($cancelled->status->isDone());
        $this->assertFalse($cancelled->status->countsAsLoad());
        $this->assertSame('Tidak dibutuhkan lagi', $cancelled->statusHistories->last()->reason);
        $this->assertRejected(fn () => $this->workflow()->transition($cancelled, S::Triage, $this->lead()));
    }

    public function test_accepted_request_is_frozen_for_changes(): void
    {
        $request = $this->request(S::Accepted);

        $this->assertRejected(fn () => $this->workflow()->transition($request, S::Cancelled, $this->lead(), 'x'));
        $this->assertRejected(fn () => $this->changes()->reviseEstimate($request, 10, 'x', $this->lead()));
        $this->assertRejected(fn () => $this->changes()->addDependency($request, ['external_party' => 'X'], $this->lead()));
    }
}
