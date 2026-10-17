<?php

namespace Tests\Feature;

use App\Enums\RequestStatus as S;
use App\Models\ServiceRequest;
use App\Models\TeamMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithWorkflow;
use Tests\TestCase;

/**
 * Alur workflow pada data awal soal (SR-101..SR-107).
 */
class SeededWorkflowTest extends TestCase
{
    use InteractsWithWorkflow, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['servops.now' => '2026-10-19T09:00:00+07:00']);
        $this->seed();
    }

    private function sr(string $code): ServiceRequest
    {
        return ServiceRequest::query()->where('code', $code)->firstOrFail();
    }

    private function teamMember(string $name): TeamMember
    {
        return TeamMember::query()->where('name', $name)->firstOrFail();
    }

    public function test_incident_sr102_can_be_resolved_same_day_by_budi(): void
    {
        $budi = $this->teamMember('Budi');

        $this->workflow()->transition($this->sr('SR-102'), S::Assigned, $this->lead(), payload: ['assignee_id' => $budi->id]);
        $this->workflow()->transition($this->sr('SR-102'), S::InProgress, $this->member('Budi'));
        $this->advanceClock(2);
        $this->workflow()->transition($this->sr('SR-102'), S::Delivered, $this->member('Budi'));
        $accepted = $this->workflow()->transition($this->sr('SR-102'), S::Accepted, $this->requester())->request;

        $this->assertTrue($accepted->status->isDone());
        $this->assertTrue($accepted->statusHistories->last()->meta['on_time']);
    }

    public function test_sr103_scarce_skill_only_assignable_to_citra(): void
    {
        $this->assertWorkflowRule('UNDERQUALIFIED', fn () => $this->workflow()->transition(
            $this->sr('SR-103'), S::Assigned, $this->lead(), payload: ['assignee_id' => $this->teamMember('Budi')->id],
        ));

        $result = $this->workflow()->transition($this->sr('SR-103'), S::Assigned, $this->lead(), payload: ['assignee_id' => $this->teamMember('Citra')->id]);

        $this->assertSame('Citra', $result->request->assignee->name);
    }

    public function test_sr104_rework_resumes_with_original_assignee(): void
    {
        $result = $this->workflow()->transition($this->sr('SR-104'), S::InProgress, $this->member('Dewi'));

        $this->assertSame(S::InProgress, $result->request->status);
        $this->assertSame(4.0, $result->request->remaining_hours);
        $this->assertGreaterThan(0, $result->request->wait_hours_total);
    }

    public function test_sr105_stays_blocked_until_finance_data_arrives(): void
    {
        $this->assertWorkflowRule('OPEN_DEPENDENCIES', fn () => $this->workflow()->transition($this->sr('SR-105'), S::InProgress, $this->member('Andi')));

        $this->changes()->resolveDependency($this->sr('SR-105')->openDependencies()->sole(), $this->member('Andi'), 'Data Q3 diterima');
        $resumed = $this->workflow()->transition($this->sr('SR-105'), S::InProgress, $this->member('Andi'))->request;

        $this->assertSame(S::InProgress, $resumed->status);
        $this->assertSame(5.5, $resumed->wait_hours_total);
    }

    public function test_sr106_cannot_be_scheduled_until_scope_is_clarified(): void
    {
        $this->assertWorkflowRule('TRANSITION_NOT_ALLOWED', fn () => $this->workflow()->transition($this->sr('SR-106'), S::Ready, $this->lead()));

        $this->workflow()->transition($this->sr('SR-106'), S::Triage, $this->lead(), 'Workshop selesai');
        $this->assertWorkflowRule('ESTIMATE_REQUIRED', fn () => $this->workflow()->transition($this->sr('SR-106'), S::Ready, $this->lead()));

        $this->changes()->reviseEstimate($this->sr('SR-106'), 10, 'Estimasi dari hasil workshop', $this->lead());
        $ready = $this->workflow()->transition($this->sr('SR-106'), S::Ready, $this->lead())->request;

        $this->assertSame(S::Ready, $ready->status);
        $this->assertSame(10.0, $ready->remaining_hours);
    }

    public function test_sr107_rejects_dewi_without_mentor_but_allows_pairing_with_andi(): void
    {
        $dewi = $this->teamMember('Dewi');
        $andi = $this->teamMember('Andi');
        $this->workflow()->transition($this->sr('SR-107'), S::Ready, $this->lead());

        $this->assertWorkflowRule('UNDERQUALIFIED', fn () => $this->workflow()->transition(
            $this->sr('SR-107'), S::Assigned, $this->lead(), payload: ['assignee_id' => $dewi->id],
        ));

        $result = $this->workflow()->transition($this->sr('SR-107'), S::Assigned, $this->lead(), payload: [
            'assignee_id' => $dewi->id,
            'pairing_member_id' => $andi->id,
            'override_reason' => 'Andi penuh; Dewi punya kapasitas, Andi review hasil',
        ]);

        $this->assertSame('UNDERQUALIFIED_ASSIGNMENT', $result->warnings[0]['code']);
        $this->assertSame($andi->id, $result->request->pairing_member_id);
    }
}
