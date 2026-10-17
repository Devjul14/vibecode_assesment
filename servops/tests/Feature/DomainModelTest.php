<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Models\RequestDependency;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\TeamMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DomainModelTest extends TestCase
{
    use RefreshDatabase;

    public function test_member_qualification_follows_required_skill_level(): void
    {
        $automation = Skill::factory()->create(['code' => 'automation']);
        $expert = TeamMember::factory()->create();
        $junior = TeamMember::factory()->create();
        $expert->skills()->attach($automation, ['level' => 3]);
        $junior->skills()->attach($automation, ['level' => 1]);

        $request = ServiceRequest::factory()->create([
            'required_skill_id' => $automation->id,
            'required_level' => 3,
        ]);

        $this->assertTrue($expert->isQualifiedFor($request));
        $this->assertFalse($junior->isQualifiedFor($request));
        $this->assertSame(0, TeamMember::factory()->create()->skillLevel($automation));
    }

    public function test_base_effective_daily_hours_deducts_focus_routine_and_meetings(): void
    {
        $member = TeamMember::factory()->create([
            'daily_hours' => 8,
            'focus_factor' => 0.75,
            'routine_hours_per_day' => 0.5,
            'meeting_hours_per_day' => 1,
        ]);

        $this->assertSame(4.5, $member->baseEffectiveDailyHours());
    }

    public function test_rework_links_to_original_request(): void
    {
        $original = ServiceRequest::factory()->status(RequestStatus::Accepted)->create();
        $rework = ServiceRequest::factory()->status(RequestStatus::Returned)->create([
            'parent_request_id' => $original->id,
            'rework_count' => 1,
        ]);

        $this->assertTrue($rework->isRework());
        $this->assertTrue($original->reworks->contains($rework));
        $this->assertFalse($original->isRework());
    }

    public function test_open_dependencies_and_dependents_are_tracked(): void
    {
        $upstream = ServiceRequest::factory()->create();
        $downstream = ServiceRequest::factory()->status(RequestStatus::Blocked)->create();

        RequestDependency::factory()->create([
            'service_request_id' => $downstream->id,
            'depends_on_request_id' => $upstream->id,
        ]);

        $this->assertTrue($downstream->hasOpenDependencies());
        $this->assertCount(1, $upstream->dependents);

        $downstream->dependencies()->update(['resolved_at' => now()]);

        $this->assertFalse($downstream->fresh()->hasOpenDependencies());
    }

    public function test_load_and_done_scopes_exclude_delivered_from_done(): void
    {
        ServiceRequest::factory()->status(RequestStatus::InProgress)->create();
        ServiceRequest::factory()->status(RequestStatus::Delivered)->create();
        ServiceRequest::factory()->status(RequestStatus::Accepted)->create();

        $this->assertSame(1, ServiceRequest::done()->count());
        $this->assertSame(1, ServiceRequest::countingAsLoad()->count());
        $this->assertSame(2, ServiceRequest::open()->count());
    }

    public function test_requests_are_routed_by_code(): void
    {
        $request = ServiceRequest::factory()->create(['code' => 'SR-101']);

        $this->assertSame('SR-101', $request->getRouteKey());
    }

    public function test_dashboard_page_renders(): void
    {
        $this->actingAs(User::factory()->manager()->create())->withoutVite()->get('/dashboard')->assertOk()->assertSee('ServOps');
    }
}
