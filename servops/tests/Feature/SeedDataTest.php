<?php

namespace Tests\Feature;

use App\Enums\DependencyType;
use App\Enums\ImpactLevel;
use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\TeamMember;
use App\Services\Calendar\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeedDataTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['servops.now' => '2026-10-19T09:00:00+07:00']);
        $this->seed();
    }

    private function request(string $code): ServiceRequest
    {
        return ServiceRequest::query()->where('code', $code)->firstOrFail();
    }

    public function test_simulation_clock_is_frozen_at_seed_anchor(): void
    {
        $this->assertSame('2026-10-19 09:00', app(Clock::class)->now()->format('Y-m-d H:i'));
    }

    public function test_assessment_requests_match_initial_data(): void
    {
        $now = CarbonImmutable::parse('2026-10-19 09:00', 'Asia/Jakarta');

        $this->assertSame(RequestStatus::Ready, $this->request('SR-101')->status);
        $this->assertTrue($this->request('SR-102')->deadline_at->isSameDay($now));
        $this->assertSame(ImpactLevel::High, $this->request('SR-102')->impact_level);
        $this->assertSame(3, $this->request('SR-103')->required_level);
        $this->assertSame(RequestStatus::Returned, $this->request('SR-104')->status);
        $this->assertSame(RequestStatus::Blocked, $this->request('SR-105')->status);
        $this->assertNull($this->request('SR-106')->estimate_hours);
        $this->assertSame(ImpactLevel::Unknown, $this->request('SR-107')->impact_level);
    }

    public function test_rework_request_keeps_effort_history(): void
    {
        $rework = $this->request('SR-104');

        $this->assertTrue($rework->isRework());
        $this->assertSame(4.0, $rework->remaining_hours);
        $this->assertSame(5.0, $rework->actual_hours);
        $this->assertSame(RequestStatus::Returned, $rework->statusHistories->last()->to_status);
        $this->assertSame('rework', $rework->estimateRevisions->last()->kind);
    }

    public function test_blocked_request_has_open_external_dependency(): void
    {
        $dependency = $this->request('SR-105')->openDependencies()->sole();

        $this->assertSame(DependencyType::External, $dependency->type);
        $this->assertSame('Tim Finance', $dependency->external_party);
    }

    public function test_only_citra_qualifies_for_scarce_automation_work(): void
    {
        $sr103 = $this->request('SR-103');
        $qualified = TeamMember::query()->with('skills')->get()->filter->isQualifiedFor($sr103);

        $this->assertSame(['Citra'], $qualified->pluck('name')->values()->all());
    }

    public function test_dewi_has_capacity_but_lacks_data_analysis_level(): void
    {
        $dewi = TeamMember::query()->where('name', 'Dewi')->firstOrFail();

        $this->assertCount(0, $dewi->activeLoad()->where('status', RequestStatus::InProgress)->get());
        $this->assertFalse($dewi->isQualifiedFor($this->request('SR-107')));
        $this->assertTrue($dewi->isQualifiedFor($this->request('SR-101')));
    }

    public function test_historical_data_is_accepted_and_contains_outliers(): void
    {
        $historical = ServiceRequest::done()->get();

        $this->assertCount(48, $historical);
        $this->assertTrue($historical->every(fn (ServiceRequest $r) => $r->accepted_at->lessThan(CarbonImmutable::parse('2026-10-19 09:00', 'Asia/Jakarta'))));
        $this->assertGreaterThanOrEqual(6, $historical->filter(fn (ServiceRequest $r) => $r->actual_hours >= 3.5 * $r->estimate_hours)->count());
        $this->assertGreaterThan(0, $historical->where('rework_count', '>', 0)->count());
    }

    public function test_status_history_ends_at_current_status(): void
    {
        ServiceRequest::query()->with('statusHistories')->get()->each(function (ServiceRequest $request) {
            $this->assertSame($request->status, $request->statusHistories->last()?->to_status, $request->code);
        });
    }
}
