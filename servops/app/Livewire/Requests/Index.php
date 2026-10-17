<?php

namespace App\Livewire\Requests;

use App\Enums\RequestStatus;
use App\Enums\RiskLevel;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\TeamMember;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Daftar request urut ranking prioritas, dengan filter status, risiko, skill, dan assignee.
 */
#[Title('Requests')]
class Index extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $scope = 'open';

    #[Url]
    public string $risk = '';

    #[Url]
    public string $skill = '';

    #[Url]
    public string $assignee = '';

    #[Url]
    public bool $reviewOnly = false;

    public function updating(string $property): void
    {
        if ($property !== 'paginators') {
            $this->resetPage();
        }
    }

    public function render(): View
    {
        $requests = ServiceRequest::query()
            ->visibleTo(auth()->user())
            ->with(['assignee', 'requiredSkill', 'requesterDepartment'])
            ->when($this->search !== '', fn (Builder $query) => $query->where(fn (Builder $q) => $q
                ->where('code', 'like', "%{$this->search}%")
                ->orWhere('title', 'like', "%{$this->search}%")))
            ->when($this->scope === 'open', fn (Builder $query) => $query->whereIn('status', RequestStatus::openStatuses()))
            ->when($this->scope === 'done', fn (Builder $query) => $query->where('status', RequestStatus::Accepted))
            ->when(RequestStatus::tryFrom($this->scope), fn (Builder $query, RequestStatus $status) => $query->where('status', $status))
            ->when($this->risk !== '', fn (Builder $query) => $query->where('risk_level', $this->risk))
            ->when($this->skill !== '', fn (Builder $query) => $query->where('required_skill_id', $this->skill))
            ->when($this->assignee === 'none', fn (Builder $query) => $query->whereNull('assignee_id'))
            ->when(ctype_digit($this->assignee), fn (Builder $query) => $query->where('assignee_id', $this->assignee))
            ->when($this->reviewOnly, fn (Builder $query) => $query->where('needs_human_review', true))
            ->orderByRaw('priority_rank is null')
            ->orderBy('priority_rank')
            ->orderByDesc('accepted_at')
            ->orderBy('deadline_at')
            ->paginate(25);

        return view('livewire.requests.index', [
            'requests' => $requests,
            'statuses' => RequestStatus::options(),
            'riskLevels' => RiskLevel::options(),
            'skills' => Skill::query()->orderBy('name')->pluck('name', 'id'),
            'members' => TeamMember::query()->orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
