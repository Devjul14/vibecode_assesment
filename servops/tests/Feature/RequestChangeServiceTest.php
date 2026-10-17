<?php

namespace Tests\Feature;

use App\Enums\CapacityEntryType;
use App\Enums\RequestStatus as S;
use App\Models\CapacityEntry;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\TeamMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithWorkflow;
use Tests\TestCase;

class RequestChangeServiceTest extends TestCase
{
    use InteractsWithWorkflow, RefreshDatabase;

    private Skill $skill;

    protected function setUp(): void
    {
        parent::setUp();

        $this->freezeClock();
        $this->skill = Skill::factory()->create();
    }

    private function memberWithLevel(int $level): TeamMember
    {
        $member = TeamMember::factory()->create();
        $member->skills()->attach($this->skill, ['level' => $level]);

        return $member;
    }

    public function test_first_estimate_is_recorded_as_initial(): void
    {
        $request = ServiceRequest::factory()->unknownEstimate()->create();

        $revision = $this->changes()->reviseEstimate($request, 10, 'Setelah workshop', $this->lead());

        $this->assertSame('initial', $revision->kind);
        $this->assertNull($revision->delta_pct);
        $this->assertSame(10.0, $request->fresh()->remaining_hours);
    }

    public function test_revision_shifts_remaining_hours_by_delta_and_flags_scope_change(): void
    {
        $request = ServiceRequest::factory()->status(S::InProgress)->create(['estimate_hours' => 12, 'remaining_hours' => 5]);

        $revision = $this->changes()->reviseEstimate($request, 20, 'Ada 2 sumber data tambahan', $this->member(), scopeChange: true);

        $this->assertSame('revision', $revision->kind);
        $this->assertSame(66.67, $revision->delta_pct);
        $this->assertTrue($revision->scope_change);
        $this->assertSame(13.0, $request->fresh()->remaining_hours);

        $event = $request->statusHistories->last();
        $this->assertSame('estimate_revised', $event->meta['event']);
        $this->assertSame($event->from_status, $event->to_status);
    }

    public function test_estimate_cannot_shrink_remaining_below_zero(): void
    {
        $request = ServiceRequest::factory()->status(S::InProgress)->create(['estimate_hours' => 10, 'remaining_hours' => 2]);

        $this->changes()->reviseEstimate($request, 5, 'Scope dikurangi', $this->lead());

        $this->assertSame(0.0, $request->fresh()->remaining_hours);
    }

    public function test_estimate_revision_rules(): void
    {
        $open = ServiceRequest::factory()->create();
        $closed = ServiceRequest::factory()->status(S::Accepted)->create();

        $this->assertWorkflowRule('INVALID_ESTIMATE', fn () => $this->changes()->reviseEstimate($open, 0, 'x', $this->lead()));
        $this->assertWorkflowRule('REASON_REQUIRED', fn () => $this->changes()->reviseEstimate($open, 4, '', $this->lead()));
        $this->assertWorkflowRule('REQUEST_CLOSED', fn () => $this->changes()->reviseEstimate($closed, 4, 'x', $this->lead()));
    }

    public function test_reassign_when_assignee_suddenly_unavailable(): void
    {
        $sick = $this->memberWithLevel(3);
        $backup = $this->memberWithLevel(2);
        $junior = $this->memberWithLevel(1);
        $request = ServiceRequest::factory()->status(S::InProgress)->create([
            'required_skill_id' => $this->skill->id,
            'required_level' => 2,
            'assignee_id' => $sick->id,
        ]);

        CapacityEntry::factory()->create([
            'team_member_id' => $sick->id,
            'type' => CapacityEntryType::Sick,
            'starts_at' => '2026-10-19 00:00',
            'ends_at' => '2026-10-21 23:59',
        ]);

        $this->assertWorkflowRule('MEMBER_UNAVAILABLE', fn () => $this->changes()->reassign($request, $sick, 'x', $this->lead()));
        $this->assertWorkflowRule('UNDERQUALIFIED', fn () => $this->changes()->reassign($request, $junior, 'Ada kapasitas', $this->lead()));
        $this->assertWorkflowRule('REASON_REQUIRED', fn () => $this->changes()->reassign($request, $backup, '', $this->lead()));

        $result = $this->changes()->reassign($request, $backup, "{$sick->name} sakit 3 hari", $this->lead());

        $this->assertTrue($result->request->assignee->is($backup));
        $this->assertSame(S::InProgress, $result->request->status);

        $event = $result->request->statusHistories->last()->meta;
        $this->assertSame('reassigned', $event['event']);
        $this->assertSame($sick->name, $event['from_assignee']);
        $this->assertSame($backup->name, $event['to_assignee']);
    }

    public function test_reassign_is_only_for_work_already_assigned(): void
    {
        $member = $this->memberWithLevel(3);
        $request = ServiceRequest::factory()->status(S::Ready)->create(['required_skill_id' => $this->skill->id]);

        $this->assertWorkflowRule('NOT_REASSIGNABLE', fn () => $this->changes()->reassign($request, $member, 'x', $this->lead()));
    }

    public function test_dependency_lifecycle_is_audited(): void
    {
        $request = ServiceRequest::factory()->status(S::InProgress)->create();

        $dependency = $this->changes()->addDependency($request, ['external_party' => 'Tim IT', 'description' => 'Akses database'], $this->lead());
        $this->assertTrue($dependency->isExternal());

        $this->advanceClock(2);
        $this->changes()->resolveDependency($dependency, $this->member(), 'Akses diberikan');

        $events = $request->statusHistories->pluck('meta.event')->all();
        $this->assertSame(['dependency_added', 'dependency_resolved'], $events);
        $this->assertSame('2026-10-19 11:00', $dependency->fresh()->resolved_at->format('Y-m-d H:i'));
        $this->assertFalse($request->hasOpenDependencies());
    }
}
