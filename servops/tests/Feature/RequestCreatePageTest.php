<?php

namespace Tests\Feature;

use App\Enums\ImpactLevel;
use App\Enums\RequestStatus;
use App\Livewire\Requests\Create;
use App\Models\Department;
use App\Models\Skill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\UsesSeededScenario;
use Tests\TestCase;

class RequestCreatePageTest extends TestCase
{
    use RefreshDatabase, UsesSeededScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedScenario();
        $this->loginAs('rudi');
    }

    public function test_validation_errors(): void
    {
        Livewire::test(Create::class)
            ->set('title', '')
            ->set('estimate_hours', '-1')
            ->call('save')
            ->assertHasErrors(['title', 'requester_department_id', 'estimate_hours']);
    }

    public function test_new_request_with_unknown_impact_and_estimate_goes_to_triage_and_is_scored(): void
    {
        Livewire::test(Create::class)
            ->set('title', 'Audit akses aplikasi keuangan')
            ->set('category', 'investigation')
            ->set('requester_department_id', Department::query()->where('code', 'FIN')->value('id'))
            ->set('required_skill_id', Skill::query()->where('code', 'data_analysis')->value('id'))
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect(route('requests.show', 'SR-108'));

        $request = $this->sr('SR-108');

        $this->assertSame(RequestStatus::Triage, $request->status);
        $this->assertSame(ImpactLevel::Unknown, $request->impact_level);
        $this->assertNull($request->estimate_hours);
        $this->assertNotNull($request->priority_rank);
        $this->assertTrue($request->needs_human_review);
        $this->assertSame([RequestStatus::New, RequestStatus::Triage], $request->statusHistories->pluck('to_status')->all());
    }
}
