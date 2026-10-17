<?php

namespace Tests\Feature;

use App\Enums\CapacityEntryType;
use App\Enums\RequestStatus;
use App\Models\CapacityEntry;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\TeamMember;
use App\Services\Capacity\CapacityService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithWorkflow;
use Tests\TestCase;

class CapacityServiceTest extends TestCase
{
    use InteractsWithWorkflow, RefreshDatabase;

    private TeamMember $member;

    protected function setUp(): void
    {
        parent::setUp();

        config(['servops.auto_recompute' => false]);
        $this->freezeClock('2026-10-19 08:00'); // Senin, awal hari kerja
        $this->member = TeamMember::factory()->create([
            'daily_hours' => 8, 'focus_factor' => 0.75, 'routine_hours_per_day' => 0.5, 'meeting_hours_per_day' => 0.5,
        ]);
    }

    private function capacity(): CapacityService
    {
        $service = app(CapacityService::class);
        $service->flush();

        return $service;
    }

    private function day(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, 'Asia/Jakarta');
    }

    public function test_effective_hours_deduct_leave_and_ad_hoc_meetings(): void
    {
        CapacityEntry::factory()->create(['team_member_id' => $this->member->id, 'type' => CapacityEntryType::Leave, 'starts_at' => '2026-10-20 00:00', 'ends_at' => '2026-10-20 23:59']);
        CapacityEntry::factory()->create(['team_member_id' => $this->member->id, 'type' => CapacityEntryType::Meeting, 'starts_at' => '2026-10-21 09:00', 'ends_at' => '2026-10-21 11:00', 'hours' => null]);
        CapacityEntry::factory()->create(['team_member_id' => $this->member->id, 'type' => CapacityEntryType::Sick, 'starts_at' => '2026-10-22 08:00', 'ends_at' => '2026-10-22 12:00']);

        $capacity = $this->capacity();

        $this->assertSame(5.0, $capacity->effectiveHoursOn($this->member, $this->day('2026-10-19')));
        $this->assertSame(0.0, $capacity->effectiveHoursOn($this->member, $this->day('2026-10-20')));
        $this->assertSame(3.0, $capacity->effectiveHoursOn($this->member, $this->day('2026-10-21')));
        $this->assertSame(2.5, $capacity->effectiveHoursOn($this->member, $this->day('2026-10-22')), 'Sakit setengah hari = separuh kapasitas');
        $this->assertSame(0.0, $capacity->effectiveHoursOn($this->member, $this->day('2026-10-24')), 'Sabtu');
    }

    public function test_capacity_utilization_and_available_hours_over_horizon(): void
    {
        ServiceRequest::factory()->status(RequestStatus::InProgress)->create(['assignee_id' => $this->member->id, 'remaining_hours' => 10]);
        ServiceRequest::factory()->status(RequestStatus::Delivered)->create(['assignee_id' => $this->member->id, 'remaining_hours' => 0]);

        $capacity = $this->capacity();

        $this->assertSame(25.0, $capacity->capacityHours($this->member));
        $this->assertSame(10.0, $capacity->committedHours($this->member));
        $this->assertSame(15.0, $capacity->availableHours($this->member));
        $this->assertSame(0.4, $capacity->utilization($this->member));
    }

    public function test_project_finish_consumes_queue_then_work_at_member_rate(): void
    {
        $capacity = $this->capacity();

        // 5 jam efektif/hari ≈ 8 jam kalender. 7.5 jam kerja = 1.5 hari → Selasa pukul 12:00.
        $this->assertEquals($this->day('2026-10-20 12:00'), $capacity->projectFinish($this->member, 2.5, 5));
        $this->assertEquals($this->day('2026-10-19 08:00'), $capacity->projectFinish($this->member, 0, 0));
    }

    public function test_project_finish_waits_for_dependency_eta(): void
    {
        $finish = $this->capacity()->projectFinish($this->member, 0, 2.5, $this->day('2026-10-21 08:00'));

        $this->assertEquals($this->day('2026-10-21 12:00'), $finish);
    }

    public function test_queue_ahead_follows_rank_and_skips_blocked_work(): void
    {
        $first = ServiceRequest::factory()->status(RequestStatus::InProgress)->create(['assignee_id' => $this->member->id, 'remaining_hours' => 3]);
        $blocked = ServiceRequest::factory()->status(RequestStatus::Blocked)->create(['assignee_id' => $this->member->id, 'remaining_hours' => 4]);
        $target = ServiceRequest::factory()->status(RequestStatus::Assigned)->create(['assignee_id' => $this->member->id, 'remaining_hours' => 2]);
        $later = ServiceRequest::factory()->status(RequestStatus::Assigned)->create(['assignee_id' => $this->member->id, 'remaining_hours' => 6]);

        $ranks = [$first->id => 1, $blocked->id => 2, $target->id => 3, $later->id => 4];

        $this->assertSame(3.0, $this->capacity()->queueAheadHours($this->member, $target, $ranks));
    }

    public function test_skill_balance_detects_bottleneck(): void
    {
        $skill = Skill::factory()->create();
        $this->member->skills()->attach($skill, ['level' => 3]);
        ServiceRequest::factory()->status(RequestStatus::Ready)->create(['required_skill_id' => $skill->id, 'remaining_hours' => 30]);

        $row = $this->capacity()->skillBalance()->firstWhere('skill.id', $skill->id);

        $this->assertSame(30.0, $row['demand']);
        $this->assertSame(25.0, $row['supply']);
        $this->assertSame('bottleneck', $row['status']);
    }
}
