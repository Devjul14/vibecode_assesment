<?php

namespace Tests\Feature;

use App\Enums\CapacityEntryType;
use App\Enums\ImpactLevel;
use App\Enums\RecommendationType;
use App\Enums\RequestStatus;
use App\Enums\RiskLevel;
use App\Models\BusinessProcess;
use App\Models\CapacityEntry;
use App\Models\Department;
use App\Models\EstimateRevision;
use App\Models\Holiday;
use App\Models\KpiSnapshot;
use App\Models\PriorityOverride;
use App\Models\Recommendation;
use App\Models\RequestDependency;
use App\Models\ScenarioRun;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\StatusHistory;
use App\Models\TeamMember;
use App\Models\TimeLog;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Fase 1 (F1.4–F1.9): config, schema, model, layout.
 */
class FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_priority_and_assignment_weights_each_sum_to_one(): void
    {
        $this->assertEqualsWithDelta(1.0, array_sum(config('servops.priority.weights')), 0.0001);
        $this->assertEqualsWithDelta(1.0, array_sum(config('servops.assignment.weights')), 0.0001);
    }

    public function test_config_carries_blueprint_defaults(): void
    {
        $this->assertSame(0.10, config('servops.priority.weights.requester'));
        $this->assertSame(3, config('servops.priority.close_call_points'));
        $this->assertSame(5, config('servops.capacity.horizon_days'));
        $this->assertSame(1.0, config('servops.capacity.overload_threshold'));
        $this->assertSame(0.6, config('servops.capacity.idle_threshold'));
        $this->assertSame(2, config('servops.risk.buffer_min_hours'));
        $this->assertSame(0.20, config('servops.risk.buffer_pct'));
        $this->assertSame(30, config('servops.risk.estimate_growth_pct'));
        $this->assertSame(5, config('servops.estimation.min_samples'));
        $this->assertSame(80, config('servops.estimation.planning_percentile'));
        $this->assertSame(['08:00', '17:00', '12:00', '13:00'], [
            config('servops.calendar.work_start'), config('servops.calendar.work_end'),
            config('servops.calendar.break_start'), config('servops.calendar.break_end'),
        ]);
    }

    public function test_all_domain_tables_exist(): void
    {
        foreach ([
            'departments', 'business_processes', 'skills', 'team_members', 'member_skills',
            'service_requests', 'request_dependencies', 'capacity_entries', 'time_logs',
            'status_histories', 'estimate_revisions', 'priority_overrides', 'holidays',
            'recommendations', 'kpi_snapshots', 'scenario_runs',
        ] as $table) {
            $this->assertTrue(Schema::hasTable($table), $table);
        }
    }

    public function test_service_requests_has_denormalized_metric_columns_and_soft_deletes(): void
    {
        $this->assertTrue(Schema::hasColumns('service_requests', [
            'priority_score', 'priority_breakdown', 'priority_rank', 'needs_human_review', 'review_reasons',
            'risk_level', 'risk_reasons', 'slack_hours', 'latest_start_at', 'projected_finish_at',
            'metrics_computed_at', 'deleted_at', 'parent_request_id', 'wait_hours_total', 'blocked_since',
        ]));

        $request = ServiceRequest::factory()->create();
        $request->delete();

        $this->assertSoftDeleted($request);
        $this->assertSame(0, ServiceRequest::count());
        $this->assertSame(1, ServiceRequest::withTrashed()->count());
    }

    public function test_service_requests_has_blueprint_indexes(): void
    {
        $indexed = collect(Schema::getIndexes('service_requests'))->pluck('columns')->all();

        foreach ([['status'], ['assignee_id', 'status'], ['deadline_at'], ['risk_level']] as $columns) {
            $this->assertContains($columns, $indexed, implode(',', $columns));
        }
    }

    public function test_request_code_is_unique(): void
    {
        ServiceRequest::factory()->create(['code' => 'SR-101']);

        $this->expectException(QueryException::class);
        ServiceRequest::factory()->create(['code' => 'SR-101']);
    }

    public function test_member_skill_pair_is_unique(): void
    {
        $member = TeamMember::factory()->create();
        $skill = Skill::factory()->create();
        $member->skills()->attach($skill, ['level' => 2]);

        $this->expectException(QueryException::class);
        $member->skills()->attach($skill, ['level' => 3]);
    }

    public function test_service_request_loads_every_relation_and_casts_fields(): void
    {
        $department = Department::factory()->create();
        $process = BusinessProcess::factory()->create(['department_id' => $department->id, 'criticality' => 5]);
        $member = TeamMember::factory()->create();
        $mentor = TeamMember::factory()->create();
        $parent = ServiceRequest::factory()->status(RequestStatus::Accepted)->create();

        $request = ServiceRequest::factory()->assignedTo($member)->create([
            'requester_department_id' => $department->id,
            'business_process_id' => $process->id,
            'pairing_member_id' => $mentor->id,
            'parent_request_id' => $parent->id,
            'impact_level' => ImpactLevel::Unknown,
            'risk_level' => RiskLevel::AtRisk,
            'priority_breakdown' => [['factor' => 'impact', 'contribution' => 17.5]],
            'review_reasons' => [['code' => 'IMPACT_UNKNOWN']],
            'needs_human_review' => 1,
        ]);

        RequestDependency::factory()->create(['service_request_id' => $request->id, 'depends_on_request_id' => $parent->id]);
        StatusHistory::factory()->create(['service_request_id' => $request->id]);
        EstimateRevision::factory()->create(['service_request_id' => $request->id]);
        PriorityOverride::factory()->create(['service_request_id' => $request->id]);
        TimeLog::factory()->create(['service_request_id' => $request->id, 'team_member_id' => $member->id]);
        Recommendation::factory()->create(['service_request_id' => $request->id, 'team_member_id' => $member->id]);

        $loaded = ServiceRequest::with([
            'requesterDepartment', 'businessProcess.department', 'requiredSkill', 'assignee', 'pairingMember',
            'parent', 'dependencies.dependsOn', 'statusHistories', 'estimateRevisions', 'priorityOverrides',
            'timeLogs.member', 'recommendations.member',
        ])->findOrFail($request->id);

        $this->assertTrue($loaded->requesterDepartment->is($department));
        $this->assertTrue($loaded->businessProcess->department->is($department));
        $this->assertTrue($loaded->assignee->is($member));
        $this->assertTrue($loaded->pairingMember->is($mentor));
        $this->assertTrue($loaded->parent->is($parent));
        $this->assertTrue($loaded->dependencies->first()->dependsOn->is($parent));
        $this->assertCount(1, $loaded->statusHistories);
        $this->assertCount(1, $loaded->estimateRevisions);
        $this->assertCount(1, $loaded->priorityOverrides);
        $this->assertTrue($loaded->timeLogs->first()->member->is($member));
        $this->assertTrue($loaded->recommendations->first()->member->is($member));

        $this->assertSame(RequestStatus::Assigned, $loaded->status);
        $this->assertSame(ImpactLevel::Unknown, $loaded->impact_level);
        $this->assertSame(RiskLevel::AtRisk, $loaded->risk_level);
        $this->assertSame('impact', $loaded->priority_breakdown[0]['factor']);
        $this->assertTrue($loaded->needs_human_review);
        $this->assertInstanceOf(CarbonInterface::class, $loaded->deadline_at);
        $this->assertIsFloat($loaded->estimate_hours);
    }

    public function test_team_member_relations_and_capacity_entries(): void
    {
        $member = TeamMember::factory()->create();
        $skill = Skill::factory()->create();
        $member->skills()->attach($skill, ['level' => 3]);
        ServiceRequest::factory()->assignedTo($member)->create();
        CapacityEntry::factory()->create(['team_member_id' => $member->id, 'type' => CapacityEntryType::Sick]);

        $loaded = TeamMember::with(['skills', 'memberSkills.skill', 'assignedRequests', 'capacityEntries'])->findOrFail($member->id);

        $this->assertSame(3, (int) $loaded->skills->first()->pivot->level);
        $this->assertTrue($loaded->memberSkills->first()->skill->is($skill));
        $this->assertCount(1, $loaded->assignedRequests);
        $this->assertSame(CapacityEntryType::Sick, $loaded->capacityEntries->first()->type);
        $this->assertTrue($skill->members->first()->is($member));
    }

    public function test_standalone_models_persist_json_and_dates(): void
    {
        $holiday = Holiday::factory()->create(['date' => '2026-12-25']);
        $snapshot = KpiSnapshot::create(['date' => '2026-10-17', 'metrics' => ['backlog' => 12]]);
        $run = ScenarioRun::create(['scenario' => 's1', 'params' => ['x' => 1], 'before' => ['a' => 1], 'after' => ['a' => 2]]);
        $recommendation = Recommendation::factory()->create(['type' => RecommendationType::Pairing]);

        $this->assertSame('2026-12-25', $holiday->fresh()->date->toDateString());
        $this->assertSame(12, $snapshot->fresh()->metrics['backlog']);
        $this->assertSame(2, $run->fresh()->after['a']);
        $this->assertSame(RecommendationType::Pairing, $recommendation->fresh()->type);
    }

    public function test_layout_shows_navigation_for_all_six_sections(): void
    {
        $response = $this->actingAs(User::factory()->manager()->create())->withoutVite()->get('/dashboard')->assertOk();

        foreach (['Dashboard', 'Requests', 'Tim &amp; Kapasitas', 'Risiko SLA', 'Rekomendasi', 'Simulasi'] as $label) {
            $response->assertSee($label, false);
        }
    }
}
