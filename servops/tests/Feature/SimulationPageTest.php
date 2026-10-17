<?php

namespace Tests\Feature;

use App\Enums\ImpactLevel;
use App\Enums\RiskLevel;
use App\Livewire\Requests\Show;
use App\Livewire\Simulation\Index;
use App\Models\ScenarioRun;
use App\Services\Calendar\Clock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\UsesSeededScenario;
use Tests\TestCase;

class SimulationPageTest extends TestCase
{
    use RefreshDatabase, UsesSeededScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedScenario();
        config(['servops.now' => null]);
        $this->withoutVite();
    }

    public function test_access_by_role(): void
    {
        $this->loginAs('andi');
        $this->get('/simulation')->assertForbidden();

        $this->loginAs('rudi');
        $this->get('/simulation')->assertOk()->assertSee('Simulasi skenario')->assertSee('Khusus Manager');

        $this->loginAs('sari');
        $this->get('/simulation')->assertOk()->assertDontSee('Khusus Manager');
    }

    public function test_preview_shows_diff_without_saving(): void
    {
        $this->loginAs('rudi');

        Livewire::test(Index::class)
            ->call('previewScenario', 'dependency_delay')
            ->assertSet('notice.type', 'success')
            ->assertSee('PREVIEW')
            ->assertSee('SR-105')
            ->assertSee('Jalankan sungguhan');

        $this->assertSame(0, ScenarioRun::query()->count());
        $this->assertSame(RiskLevel::AtRisk, $this->sr('SR-105')->risk_level);
    }

    public function test_run_scenario_with_parameters_and_view_history(): void
    {
        $this->loginAs('rudi');

        $component = Livewire::test(Index::class)
            ->set('memberId', $this->teamMember('Andi')->id)
            ->set('days', 3)
            ->call('runScenario', 'member_unavailable')
            ->assertSet('notice.type', 'success')
            ->assertSee('DIJALANKAN')
            ->assertSee('Rekomendasi baru');

        $run = ScenarioRun::query()->sole();
        $this->assertSame(3, $run->params['days']);
        $this->assertSame('Rudi Hartono (Team Lead)', $run->params['actor']);
        $this->assertContains('ASSIGNEE_UNAVAILABLE', $this->riskCodes('SR-095'));

        $component->call('showRun', $run->id)->assertSee('Anggota kritis mendadak tidak tersedia');
    }

    public function test_clock_controls(): void
    {
        $this->loginAs('rudi');

        Livewire::test(Index::class)
            ->call('advance', 4)
            ->assertSet('notice.type', 'success');

        $this->assertSame('2026-10-19 14:00', app(Clock::class)->now()->format('Y-m-d H:i'), '09:00 + 4 jam kerja melewati istirahat 12–13');
        $this->assertSame(1, ScenarioRun::query()->count());
    }

    public function test_manager_only_scenario_is_refused_for_team_lead(): void
    {
        $this->loginAs('rudi');

        Livewire::test(Index::class)
            ->call('runScenario', 'urgent_insert')
            ->assertSet('notice.type', 'error');

        $this->assertSame(0, ScenarioRun::query()->count());
    }

    public function test_reset_disabled_shows_message(): void
    {
        config(['servops.allow_reset' => false]);
        $this->loginAs('sari');

        Livewire::test(Index::class)
            ->call('resetData')
            ->assertSet('notice.type', 'error')
            ->assertNoRedirect();
    }

    public function test_impact_triage_form_on_request_page(): void
    {
        $this->loginAs('rudi');

        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-107')])
            ->assertSee('Triage dampak (wajib)')
            ->set('impactLevel', 'high')
            ->set('impactDepartments', 3)
            ->set('impactReason', 'Selisih memengaruhi laporan pajak')
            ->call('assessImpact')
            ->assertSet('notice.type', 'success');

        $this->assertSame(ImpactLevel::High, $this->sr('SR-107')->impact_level);
        $this->assertNotContains('IMPACT_UNKNOWN', $this->reviewCodes('SR-107'));

        $this->loginAs('andi');
        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-107')])->assertDontSee('Nilai ulang dampak');
    }
}
