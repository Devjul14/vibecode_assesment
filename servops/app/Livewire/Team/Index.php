<?php

namespace App\Livewire\Team;

use App\Enums\CapacityEntryType;
use App\Livewire\Concerns\InteractsWithServOps;
use App\Enums\RequestStatus;
use App\Models\CapacityEntry;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\TeamMember;
use App\Services\Calendar\Clock;
use App\Services\Capacity\CapacityEntryService;
use App\Services\Capacity\CapacityService;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Gambaran kapasitas: beban vs kapasitas efektif, kapasitas per hari, matriks kompetensi,
 * bottleneck skill, dan pencatatan cuti / sakit / rapat.
 */
#[Title('Tim & Kapasitas')]
class Index extends Component
{
    use InteractsWithServOps;

    public ?int $memberId = null;

    public string $type = 'sick';

    public string $startsAt = '';

    public string $endsAt = '';

    public ?string $hours = null;

    public string $note = '';

    public function mount(Clock $clock): void
    {
        $this->memberId = auth()->user()->can('capacity.manage') ? null : auth()->user()->team_member_id;
        $today = $clock->now()->startOfDay();
        $this->startsAt = $today->format('Y-m-d\TH:i');
        $this->endsAt = $today->endOfDay()->format('Y-m-d\TH:i');
    }

    public function addEntry(CapacityEntryService $service): void
    {
        $this->validate([
            'memberId' => ['required', 'exists:team_members,id'],
            'type' => ['required', Rule::enum(CapacityEntryType::class)],
            'startsAt' => ['required', 'date'],
            'endsAt' => ['required', 'date'],
            'hours' => ['nullable', 'numeric', 'gt:0', 'max:24'],
        ]);

        $member = TeamMember::query()->findOrFail($this->memberId);
        $type = CapacityEntryType::from($this->type);

        if ($this->attempt(
            fn () => $this->canManageCapacityOf($member->id) ? $service->add(
                $member,
                $type,
                CarbonImmutable::parse($this->startsAt, config('app.timezone')),
                CarbonImmutable::parse($this->endsAt, config('app.timezone')),
                $this->hours !== null && $this->hours !== '' ? (float) $this->hours : null,
                $this->note ?: null,
            ) : throw new AuthorizationException,
            "{$type->label()} untuk {$member->name} dicatat — risiko & rekomendasi dihitung ulang.",
        )) {
            $this->reset('hours', 'note');
        }
    }

    public function removeEntry(int $id, CapacityEntryService $service): void
    {
        $entry = CapacityEntry::query()->findOrFail($id);
        $this->attempt(
            fn () => $this->canManageCapacityOf($entry->team_member_id) ? $service->remove($entry) : throw new AuthorizationException,
            'Entri kapasitas dihapus.',
        );
    }

    /** Kelola kapasitas siapa pun, atau hanya diri sendiri untuk anggota. */
    public function canManageCapacityOf(int $memberId): bool
    {
        $user = auth()->user();

        return $user->can('capacity.manage')
            || ($user->can('capacity.manage_own') && $user->team_member_id === $memberId);
    }

    public function render(CapacityService $capacity, Clock $clock): View
    {
        $capacity->flush();
        $members = TeamMember::query()->active()->with('skills')->orderBy('name')->get();
        $unassigned = ServiceRequest::query()
            ->whereNull('assignee_id')
            ->whereIn('status', [RequestStatus::Ready, RequestStatus::Triage, RequestStatus::NeedsClarification])
            ->orderBy('priority_rank')
            ->get();
        $days = array_slice($capacity->timeline($members->first() ?? new TeamMember), 0, (int) config('servops.capacity.horizon_days'));

        return view('livewire.team.index', [
            'snapshot' => $capacity->teamSnapshot()->keyBy(fn (array $row) => $row['member']->id),
            'members' => $members,
            'skills' => Skill::query()->orderBy('name')->get(),
            'skillBalance' => $capacity->skillBalance(),
            'days' => collect($days)->map(fn (array $day) => $day['start']->startOfDay()),
            'heatmap' => $members->mapWithKeys(fn (TeamMember $member) => [
                $member->id => collect(array_slice($capacity->timeline($member), 0, count($days)))
                    ->map(fn (array $day) => ['hours' => $day['hours'], 'absence' => $capacity->absenceBetween($member, $day['start'], $day['end'])?->type])
                    ->all(),
            ]),
            'entries' => CapacityEntry::query()->with('member')->where('ends_at', '>=', $clock->now()->startOfDay())->orderBy('starts_at')->get(),
            'types' => CapacityEntryType::options(),
            'unassigned' => $unassigned,
            'canTake' => $members->mapWithKeys(fn (TeamMember $member) => [
                $member->id => $unassigned->filter(fn (ServiceRequest $request) => $member->isQualifiedFor($request))->pluck('code')->all(),
            ]),
            'manageableMembers' => $members->filter(fn (TeamMember $member) => $this->canManageCapacityOf($member->id)),
            'now' => $clock->now(),
        ]);
    }
}
