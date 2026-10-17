<?php

namespace Tests\Feature;

use App\Enums\ActorRole;
use App\Enums\Permission;
use App\Enums\RequestStatus;
use App\Livewire\Requests\Index as RequestIndex;
use App\Livewire\Requests\Show;
use App\Livewire\Team\Index as TeamIndex;
use App\Models\CapacityEntry;
use App\Models\Recommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesSeededScenario;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase, UsesSeededScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedScenario();
        $this->withoutVite();
    }

    /** @return array<string, array{0: string, 1: string, 2: int}> */
    public static function pageAccess(): array
    {
        return [
            'requester → dashboard' => ['ops', '/dashboard', 403],
            'requester → team' => ['ops', '/team', 403],
            'requester → risk' => ['ops', '/risk', 403],
            'requester → recommendations' => ['ops', '/recommendations', 403],
            'requester → own dept request' => ['ops', '/requests/SR-102', 200],
            'requester → other dept request' => ['ops', '/requests/SR-101', 403],
            'requester → create' => ['ops', '/requests/create', 200],
            'member → dashboard' => ['andi', '/dashboard', 200],
            'member → any request' => ['andi', '/requests/SR-103', 200],
            'lead → team' => ['rudi', '/team', 200],
            'manager → recommendations' => ['sari', '/recommendations', 200],
        ];
    }

    #[DataProvider('pageAccess')]
    public function test_page_access_matrix(string $account, string $uri, int $status): void
    {
        $this->loginAs($account);

        $this->get($uri)->assertStatus($status);
    }

    public function test_manager_has_every_permission_and_requester_only_two(): void
    {
        $this->assertCount(count(Permission::cases()), ActorRole::Manager->permissions());
        $this->assertSame([Permission::CreateRequest, Permission::AcceptOwnDelivery], ActorRole::Requester->permissions());
        $this->assertFalse(ActorRole::TeamLead->can(Permission::OverridePriority));
        $this->assertFalse(ActorRole::TeamLead->can(Permission::AcceptAnyDelivery));
    }

    public function test_requester_only_sees_own_department_requests(): void
    {
        $this->loginAs('ops');

        Livewire::test(RequestIndex::class)
            ->assertSee('SR-102')
            ->assertSee('SR-103')
            ->assertDontSee('SR-101')
            ->assertDontSee('SR-107');
    }

    public function test_member_can_work_own_request_but_not_others(): void
    {
        $this->loginAs('andi');

        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-095')])
            ->set('target', 'delivered')
            ->call('changeStatus')
            ->assertSet('notice.type', 'success');

        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-097')])
            ->set('target', 'delivered')
            ->call('changeStatus')
            ->assertSet('notice.type', 'error');

        $this->assertSame(RequestStatus::Delivered, $this->sr('SR-095')->status);
        $this->assertSame(RequestStatus::InProgress, $this->sr('SR-097')->status);
    }

    public function test_member_cannot_assign_or_decide_recommendations(): void
    {
        $this->loginAs('andi');

        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-102')])
            ->assertDontSee('Ubah status')
            ->call('assignTo', $this->teamMember('Budi')->id)
            ->assertSet('notice.type', 'error')
            ->call('acceptRecommendation', Recommendation::query()->where('title', 'Tugaskan SR-102 ke Budi')->value('id'))
            ->assertSet('notice.type', 'error');

        $this->assertNull($this->sr('SR-102')->assignee_id);
    }

    public function test_member_estimate_only_on_own_work(): void
    {
        $this->loginAs('andi');

        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-097')])
            ->assertDontSee('Revisi estimasi')
            ->set('newEstimate', '20')
            ->set('estimateReason', 'x')
            ->call('reviseEstimate')
            ->assertSet('notice.type', 'error');

        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-095')])
            ->assertSee('Revisi estimasi');
    }

    public function test_team_lead_cannot_accept_delivery_but_requester_of_department_can(): void
    {
        $this->loginAs('rudi');
        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-102')])->call('assignTo', $this->teamMember('Budi')->id);
        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-102')])->set('target', 'in_progress')->call('changeStatus');
        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-102')])->set('target', 'delivered')->call('changeStatus');

        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-102')])
            ->set('target', 'accepted')
            ->call('changeStatus')
            ->assertSet('notice.type', 'error');

        $this->loginAs('sales');
        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-102')])->assertForbidden();

        $this->loginAs('ops');
        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-102')])
            ->set('target', 'accepted')
            ->call('changeStatus')
            ->assertSet('notice.type', 'success');

        $this->assertSame(RequestStatus::Accepted, $this->sr('SR-102')->status);
        $this->assertSame('Bayu Pratama', $this->sr('SR-102')->statusHistories->last()->actor_name);
    }

    public function test_member_manages_only_own_capacity(): void
    {
        $andi = $this->loginAs('andi');

        Livewire::test(TeamIndex::class)
            ->assertSet('memberId', $andi->team_member_id)
            ->set('memberId', $this->teamMember('Budi')->id)
            ->set('startsAt', '2026-10-20T08:00')
            ->set('endsAt', '2026-10-20T17:00')
            ->call('addEntry')
            ->assertSet('notice.type', 'error')
            ->set('memberId', $andi->team_member_id)
            ->call('addEntry')
            ->assertSet('notice.type', 'success');

        $this->assertSame(1, CapacityEntry::query()->where('team_member_id', $andi->team_member_id)->count());
        $this->assertSame(0, CapacityEntry::query()->where('team_member_id', $this->teamMember('Budi')->id)->count());
    }

    public function test_audit_log_records_logged_in_user(): void
    {
        $this->loginAs('rudi');

        Livewire::test(Show::class, ['serviceRequest' => $this->sr('SR-102')])->call('assignTo', $this->teamMember('Budi')->id);

        $entry = $this->sr('SR-102')->statusHistories->last();
        $this->assertSame('Rudi Hartono', $entry->actor_name);
        $this->assertSame(ActorRole::TeamLead, $entry->actor_role);
    }
}
