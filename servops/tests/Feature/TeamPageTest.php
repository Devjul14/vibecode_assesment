<?php

namespace Tests\Feature;

use App\Livewire\Team\Index;
use App\Models\CapacityEntry;
use App\Models\Recommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\Concerns\UsesSeededScenario;
use Tests\TestCase;

class TeamPageTest extends TestCase
{
    use RefreshDatabase, UsesSeededScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedScenario();
        $this->loginAs('rudi');
    }

    public function test_recording_sick_leave_recomputes_risk_and_recommendations(): void
    {
        Livewire::test(Index::class)
            ->set('memberId', $this->teamMember('Andi')->id)
            ->set('type', 'sick')
            ->set('startsAt', '2026-10-19T00:00')
            ->set('endsAt', '2026-10-21T23:59')
            ->call('addEntry')
            ->assertSet('notice.type', 'success')
            ->assertSee('Sakit');

        $this->assertContains('ASSIGNEE_UNAVAILABLE', $this->riskCodes('SR-095'));
        $this->assertTrue(Recommendation::query()->open()->where('service_request_id', $this->sr('SR-095')->id)->exists());
    }

    public function test_invalid_range_is_rejected_and_entry_can_be_removed(): void
    {
        $component = Livewire::test(Index::class)
            ->set('memberId', $this->teamMember('Budi')->id)
            ->set('startsAt', '2026-10-20T10:00')
            ->set('endsAt', '2026-10-20T09:00')
            ->call('addEntry')
            ->assertSet('notice.type', 'error');

        $entry = CapacityEntry::query()->firstOrFail();
        $component->call('removeEntry', $entry->id)->assertSet('notice.type', 'success');

        $this->assertModelMissing($entry);
    }
}
