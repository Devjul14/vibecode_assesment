<?php

namespace Tests\Feature;

use App\Enums\RequestStatus;
use App\Livewire\Requests\Show;
use App\Models\PriorityOverride;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\UsesSeededScenario;
use Tests\TestCase;

class RequestDetailPageTest extends TestCase
{
    use RefreshDatabase, UsesSeededScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedScenario();
        $this->loginAs('rudi');
    }

    public function test_assign_best_candidate_from_candidate_list(): void
    {
        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-102')])
            ->assertSee('Budi')
            ->call('assignTo', $this->teamMember('Budi')->id)
            ->assertSet('notice.type', 'success');

        $this->assertSame(RequestStatus::Assigned, $this->sr('SR-102')->status);
        $this->assertSame('Rudi Hartono', $this->sr('SR-102')->statusHistories->last()->actor_name);
    }

    public function test_guard_violation_is_shown_as_message(): void
    {
        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-106')])
            ->set('target', 'triage')
            ->set('reason', 'Workshop selesai')
            ->call('changeStatus')
            ->assertSet('notice.type', 'success')
            ->set('target', 'ready')
            ->call('changeStatus')
            ->assertSet('notice.type', 'error')
            ->assertSee('Estimasi belum diketahui');

        $this->assertSame(RequestStatus::Triage, $this->sr('SR-106')->status);
    }

    public function test_return_for_rework_collects_hours_and_cause(): void
    {
        $sr = $this->sr('SR-096');
        Livewire::test(Show::class, ['serviceRequest' => $sr])->set('target', 'delivered')->call('changeStatus');

        $this->loginAs('ops');
        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-096')])
            ->set('target', 'returned')
            ->set('reason', 'Masih ada user yang tidak bisa login')
            ->set('reworkHours', '1.5')
            ->set('reworkCause', 'quality')
            ->call('changeStatus')
            ->assertSet('notice.type', 'success');

        $this->assertSame(RequestStatus::Returned, $this->sr('SR-096')->status);
        $this->assertSame(1.5, $this->sr('SR-096')->remaining_hours);
    }

    public function test_block_with_dependency_and_resolve(): void
    {
        $component = Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-095')])
            ->set('target', 'blocked')
            ->set('depParty', 'Tim Sales')
            ->set('depDescription', 'Target penjualan per region')
            ->set('depEta', '2026-10-20T12:00')
            ->call('changeStatus')
            ->assertSet('notice.type', 'success');

        $dependency = $this->sr('SR-095')->openDependencies()->sole();
        $this->assertSame('Tim Sales', $dependency->external_party);

        $component->call('resolveDependency', $dependency->id)->assertSet('notice.type', 'success');
        $this->assertFalse($this->sr('SR-095')->hasOpenDependencies());
    }

    public function test_estimate_revision_from_page(): void
    {
        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-097')])
            ->set('newEstimate', '24')
            ->set('estimateReason', 'API vendor tambah 2 endpoint')
            ->set('scopeChange', true)
            ->call('reviseEstimate')
            ->assertSet('notice.type', 'success');

        $this->assertSame(24.0, $this->sr('SR-097')->estimate_hours);
        $this->assertContains('ESTIMATE_GROWTH', $this->riskCodes('SR-097'));
    }

    public function test_override_requires_manager(): void
    {
        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-107')])
            ->set('overrideJustification', 'Regulator')
            ->call('applyOverride')
            ->assertSet('notice.type', 'error')
            ->assertSet('notice.message', 'Peran Anda tidak memiliki akses untuk aksi ini.');

        $this->assertSame(0, PriorityOverride::query()->count());
    }

    public function test_manager_previews_then_applies_override(): void
    {
        $this->loginAs('sari');

        $component = Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-107')])
            ->set('overrideJustification', 'Selisih data masuk laporan regulator')
            ->call('previewOverride')
            ->assertSet('notice.type', 'success');

        $this->assertNotEmpty($component->get('preview'));
        $this->assertSame(0, PriorityOverride::query()->count(), 'Preview tidak boleh menyimpan');
        $this->assertSame(1, $this->sr('SR-102')->priority_rank);

        $component->call('applyOverride')->assertSet('notice.type', 'success');

        $this->assertSame(1, $this->sr('SR-107')->priority_rank);
        $this->assertSame('priority_overridden', $this->sr('SR-107')->statusHistories->last()->meta['event']);
    }

    public function test_reassign_requires_reason(): void
    {
        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-095')])
            ->set('reassignTo', $this->teamMember('Citra')->id)
            ->call('reassign')
            ->assertSet('notice.type', 'error')
            ->set('reassignReason', 'Andi fokus SR-105')
            ->call('reassign')
            ->assertSet('notice.type', 'success');

        $this->assertSame('Citra', $this->sr('SR-095')->assignee->name);
    }
}
