<?php

namespace Tests\Feature;

use App\Enums\CapacityEntryType;
use App\Enums\RequestStatus as S;
use App\Enums\ReworkCause;
use App\Models\CapacityEntry;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\StatusHistory;
use App\Models\TeamMember;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithWorkflow;
use Tests\TestCase;

class WorkflowTransitionTest extends TestCase
{
    use InteractsWithWorkflow, RefreshDatabase;

    private Skill $skill;

    private CarbonImmutable $now;

    protected function setUp(): void
    {
        parent::setUp();

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

    public function test_transition_outside_state_machine_is_rejected_without_side_effects(): void
    {
        $request = $this->request(S::Ready);

        $this->assertWorkflowRule('TRANSITION_NOT_ALLOWED', fn () => $this->workflow()->transition($request, S::Delivered, $this->lead()));
        $this->assertSame(S::Ready, $request->fresh()->status);
        $this->assertSame(0, StatusHistory::count());
    }

    public function test_ready_requires_known_estimate_and_sets_remaining_hours(): void
    {
        $request = $this->request(S::Triage, ['estimate_hours' => null, 'remaining_hours' => null]);

        $this->assertWorkflowRule('ESTIMATE_REQUIRED', fn () => $this->workflow()->transition($request, S::Ready, $this->lead()));

        $this->changes()->reviseEstimate($request, 6, 'Hasil workshop', $this->lead());
        $result = $this->workflow()->transition($request, S::Ready, $this->lead());

        $this->assertSame(S::Ready, $result->request->status);
        $this->assertSame(6.0, $result->request->remaining_hours);
    }

    public function test_ready_requires_required_skill(): void
    {
        $request = $this->request(S::Triage, ['required_skill_id' => null]);

        $this->assertWorkflowRule('SKILL_REQUIRED', fn () => $this->workflow()->transition($request, S::Ready, $this->lead()));
    }

    public function test_reason_is_required_for_clarification_and_cancellation(): void
    {
        $request = $this->request(S::Triage);

        $this->assertWorkflowRule('REASON_REQUIRED', fn () => $this->workflow()->transition($request, S::NeedsClarification, $this->lead()));
        $this->assertWorkflowRule('REASON_REQUIRED', fn () => $this->workflow()->transition($request, S::Cancelled, $this->lead(), '  '));

        $result = $this->workflow()->transition($request, S::NeedsClarification, $this->lead(), 'Scope belum jelas');
        $this->assertSame(S::NeedsClarification, $result->request->status);
    }

    public function test_assignment_requires_qualified_member(): void
    {
        $request = $this->request(S::Ready);
        $junior = $this->memberWithLevel(1);
        $senior = $this->memberWithLevel(3);

        $this->assertWorkflowRule('ASSIGNEE_REQUIRED', fn () => $this->workflow()->transition($request, S::Assigned, $this->lead()));
        $this->assertWorkflowRule('UNDERQUALIFIED', fn () => $this->workflow()->transition($request, S::Assigned, $this->lead(), payload: ['assignee_id' => $junior->id]));

        $result = $this->workflow()->transition($request, S::Assigned, $this->lead(), payload: ['assignee_id' => $senior->id]);

        $this->assertTrue($result->request->assignee->is($senior));
        $this->assertFalse($result->hasWarnings());
    }

    public function test_underqualified_assignment_needs_qualified_mentor_and_reason(): void
    {
        $request = $this->request(S::Ready);
        $junior = $this->memberWithLevel(1);
        $otherJunior = $this->memberWithLevel(1);
        $mentor = $this->memberWithLevel(3);

        $this->assertWorkflowRule('UNDERQUALIFIED', fn () => $this->workflow()->transition($request, S::Assigned, $this->lead(), payload: [
            'assignee_id' => $junior->id, 'pairing_member_id' => $mentor->id,
        ]));
        $this->assertWorkflowRule('INVALID_MENTOR', fn () => $this->workflow()->transition($request, S::Assigned, $this->lead(), payload: [
            'assignee_id' => $junior->id, 'pairing_member_id' => $otherJunior->id, 'override_reason' => 'Belajar',
        ]));

        $result = $this->workflow()->transition($request, S::Assigned, $this->lead(), payload: [
            'assignee_id' => $junior->id, 'pairing_member_id' => $mentor->id, 'override_reason' => 'Mentor tersedia, expert penuh',
        ]);

        $this->assertSame($mentor->id, $result->request->pairing_member_id);
        $this->assertSame('UNDERQUALIFIED_ASSIGNMENT', $result->warnings[0]['code']);
        $this->assertSame($mentor->name, $result->request->statusHistories->last()->meta['pairing_with']);
    }

    public function test_absent_or_inactive_member_cannot_be_assigned(): void
    {
        $request = $this->request(S::Ready);
        $sick = $this->memberWithLevel(3);
        $inactive = $this->memberWithLevel(3, ['is_active' => false]);

        CapacityEntry::factory()->create([
            'team_member_id' => $sick->id,
            'type' => CapacityEntryType::Sick,
            'starts_at' => '2026-10-19 00:00',
            'ends_at' => '2026-10-20 23:59',
        ]);

        $this->assertWorkflowRule('MEMBER_UNAVAILABLE', fn () => $this->workflow()->transition($request, S::Assigned, $this->lead(), payload: ['assignee_id' => $sick->id]));
        $this->assertWorkflowRule('MEMBER_INACTIVE', fn () => $this->workflow()->transition($request, S::Assigned, $this->lead(), payload: ['assignee_id' => $inactive->id]));
    }

    public function test_meeting_entry_does_not_block_assignment(): void
    {
        $request = $this->request(S::Ready);
        $member = $this->memberWithLevel(2);

        CapacityEntry::factory()->create([
            'team_member_id' => $member->id,
            'type' => CapacityEntryType::Meeting,
            'starts_at' => '2026-10-19 08:30',
            'ends_at' => '2026-10-19 10:00',
        ]);

        $result = $this->workflow()->transition($request, S::Assigned, $this->lead(), payload: ['assignee_id' => $member->id]);

        $this->assertSame(S::Assigned, $result->request->status);
    }

    public function test_start_is_blocked_by_open_dependency_and_warns_on_wip_limit(): void
    {
        $member = $this->memberWithLevel(3, ['wip_limit' => 1]);
        $upstream = $this->request(S::InProgress, ['assignee_id' => $member->id]);
        $request = $this->request(S::Assigned, ['assignee_id' => $member->id]);
        $dependency = $this->changes()->addDependency($request, ['depends_on_request_id' => $upstream->id, 'description' => 'Butuh hasil upstream'], $this->lead());

        $this->assertWorkflowRule('OPEN_DEPENDENCIES', fn () => $this->workflow()->transition($request, S::InProgress, $this->member()));

        $this->changes()->resolveDependency($dependency, $this->lead());
        $result = $this->workflow()->transition($request, S::InProgress, $this->member());

        $this->assertSame('WIP_LIMIT', $result->warnings[0]['code']);
        $this->assertEquals($this->now, $result->request->started_at);
    }

    public function test_block_and_unblock_track_dependency_and_wait_time(): void
    {
        $member = $this->memberWithLevel(3);
        $request = $this->request(S::InProgress, ['assignee_id' => $member->id]);

        $this->assertWorkflowRule('REASON_REQUIRED', fn () => $this->workflow()->transition($request, S::Blocked, $this->member()));

        $blocked = $this->workflow()->transition($request, S::Blocked, $this->member(), payload: [
            'dependency' => ['external_party' => 'Tim Finance', 'description' => 'Data Q3', 'expected_at' => '2026-10-20 15:00'],
        ])->request;
        $dependency = $blocked->openDependencies()->sole();

        $this->assertSame('Tim Finance', $dependency->external_party);
        $this->assertNotNull($blocked->blocked_since);

        $this->advanceClock(3);
        $this->assertWorkflowRule('OPEN_DEPENDENCIES', fn () => $this->workflow()->transition($blocked, S::InProgress, $this->member()));

        $this->changes()->resolveDependency($dependency, $this->member(), 'Data diterima');
        $resumed = $this->workflow()->transition($blocked, S::InProgress, $this->member())->request;

        $this->assertNull($resumed->blocked_since);
        $this->assertSame(3.0, $resumed->wait_hours_total);
        $this->assertEquals(3, $resumed->statusHistories->last()->meta['blocked_hours']);
    }

    public function test_acceptance_is_limited_to_requester_or_manager(): void
    {
        $request = $this->request(S::Delivered, ['delivered_at' => '2026-10-19 08:00', 'deadline_at' => '2026-10-19 17:00']);

        $this->assertWorkflowRule('ACCEPTANCE_ROLE', fn () => $this->workflow()->transition($request, S::Accepted, $this->member()));

        $accepted = $this->workflow()->transition($request, S::Accepted, $this->requester())->request;

        $this->assertTrue($accepted->status->isDone());
        $this->assertNotNull($accepted->accepted_at);
        $this->assertTrue($accepted->statusHistories->last()->meta['on_time']);
    }

    public function test_returned_work_adds_rework_load_and_is_not_done(): void
    {
        $member = $this->memberWithLevel(2);
        $request = $this->request(S::Delivered, ['assignee_id' => $member->id, 'estimate_hours' => 6, 'remaining_hours' => 0]);

        $this->assertWorkflowRule('REASON_REQUIRED', fn () => $this->workflow()->transition($request, S::Returned, $this->requester()));
        $this->assertWorkflowRule('REWORK_ESTIMATE_REQUIRED', fn () => $this->workflow()->transition($request, S::Returned, $this->requester(), 'Tidak sesuai'));
        $this->assertWorkflowRule('REWORK_CAUSE_REQUIRED', fn () => $this->workflow()->transition($request, S::Returned, $this->requester(), 'Tidak sesuai', ['rework_estimate_hours' => 3]));

        $returned = $this->workflow()->transition($request, S::Returned, $this->requester(), 'Definisi KPI salah', [
            'rework_estimate_hours' => 3,
            'rework_cause' => ReworkCause::UnclearScope,
        ])->request;

        $this->assertSame(1, $returned->rework_count);
        $this->assertSame(3.0, $returned->remaining_hours);
        $this->assertSame(9.0, $returned->estimate_hours);
        $this->assertSame('rework', $returned->estimateRevisions->last()->kind);
        $this->assertTrue($returned->status->countsAsLoad());
        $this->assertSame(0, ServiceRequest::done()->count());

        $resumed = $this->workflow()->transition($returned, S::InProgress, $this->member())->request;
        $this->assertTrue($resumed->statusHistories->last()->meta['rework_started']);
    }

    public function test_waiting_statuses_accumulate_working_hours_only(): void
    {
        $member = $this->memberWithLevel(2);
        $request = $this->request(S::Triage);

        $ready = $this->workflow()->transition($request, S::Ready, $this->lead())->request;
        $this->advanceClock(24 * 3); // Senin 09:00 → Kamis 09:00 = 3 hari kerja

        $assigned = $this->workflow()->transition($ready, S::Assigned, $this->lead(), payload: ['assignee_id' => $member->id])->request;

        $this->assertSame(24.0, $assigned->wait_hours_total);
        $this->assertEquals(24, $assigned->statusHistories->last()->meta['wait_hours']);
    }

    public function test_history_records_actor_reason_and_simulated_time(): void
    {
        $request = $this->request(S::Triage);

        $this->workflow()->transition($request, S::NeedsClarification, $this->lead(), 'Perlu workshop');
        $entry = StatusHistory::query()->latest('id')->first();

        $this->assertSame(S::Triage, $entry->from_status);
        $this->assertSame(S::NeedsClarification, $entry->to_status);
        $this->assertSame('Lead', $entry->actor_name);
        $this->assertSame('Perlu workshop', $entry->reason);
        $this->assertSame('2026-10-19 09:00', $entry->created_at->format('Y-m-d H:i'));
    }

    public function test_scope_change_sends_in_progress_work_back_to_triage(): void
    {
        $member = $this->memberWithLevel(2);
        $request = $this->request(S::InProgress, ['assignee_id' => $member->id]);

        $this->assertWorkflowRule('REASON_REQUIRED', fn () => $this->workflow()->transition($request, S::Triage, $this->member()));

        $result = $this->workflow()->transition($request, S::Triage, $this->member(), 'Scope bertambah: 3 sumber data baru');

        $this->assertSame(S::Triage, $result->request->status);
    }
}
