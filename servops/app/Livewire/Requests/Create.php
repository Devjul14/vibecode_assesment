<?php

namespace App\Livewire\Requests;

use App\Enums\EstimateConfidence;
use App\Enums\ImpactLevel;
use App\Enums\RequesterPriority;
use App\Livewire\Concerns\InteractsWithServOps;
use App\Models\BusinessProcess;
use App\Models\Department;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Services\Calendar\Clock;
use App\Services\Calendar\WorkingCalendar;
use App\Services\Workflow\RequestIntakeService;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Pencatatan request baru. Dampak boleh "belum diketahui" dan estimasi boleh kosong —
 * sistem akan menandainya untuk triage, bukan memaksa pemohon menebak.
 */
#[Title('Request baru')]
class Create extends Component
{
    use InteractsWithServOps;

    public const CATEGORIES = ['reporting', 'analysis', 'incident', 'automation', 'change', 'investigation'];

    public string $title = '';

    public string $description = '';

    public string $category = 'reporting';

    public ?int $requester_department_id = null;

    public ?int $business_process_id = null;

    public string $requester_priority = 'medium';

    public string $impact_level = 'unknown';

    public ?int $affected_users = null;

    public ?int $affected_departments = null;

    public ?int $required_skill_id = null;

    public int $required_level = 2;

    public ?string $estimate_hours = null;

    public string $estimate_confidence = 'medium';

    public string $deadline_at = '';

    public function mount(Clock $clock, WorkingCalendar $calendar): void
    {
        $this->authorize('create', ServiceRequest::class);
        $this->requester_department_id = auth()->user()->department_id;
        $this->deadline_at = $calendar->endOfWorkingDay($clock->now(), 3)->format('Y-m-d\TH:i');
    }

    /** @return array<string, mixed> */
    protected function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'requester_department_id' => ['required', 'exists:departments,id'],
            'business_process_id' => ['nullable', 'exists:business_processes,id'],
            'requester_priority' => ['required', Rule::enum(RequesterPriority::class)],
            'impact_level' => ['required', Rule::enum(ImpactLevel::class)],
            'affected_users' => ['nullable', 'integer', 'min:0'],
            'affected_departments' => ['nullable', 'integer', 'min:0', 'max:50'],
            'required_skill_id' => ['nullable', 'exists:skills,id'],
            'required_level' => ['required', 'integer', 'between:1,3'],
            'estimate_hours' => ['nullable', 'numeric', 'gt:0', 'max:500'],
            'estimate_confidence' => ['required', Rule::enum(EstimateConfidence::class)],
            'deadline_at' => ['required', 'date'],
        ];
    }

    public function save(RequestIntakeService $intake): void
    {
        $data = $this->validate();

        if ($this->lockedDepartment()) {
            $data['requester_department_id'] = auth()->user()->department_id;
        }
        $request = $intake->create($data, $this->actor());

        session()->flash('notice', ['type' => 'success', 'message' => "{$request->code} dicatat dan masuk triage."]);
        $this->redirectRoute('requests.show', $request, navigate: true);
    }

    /** Pemohon hanya bisa mencatat request atas nama departemennya sendiri. */
    public function lockedDepartment(): bool
    {
        return auth()->user()->department_id !== null && ! auth()->user()->can('requests.view_all');
    }

    public function render(): View
    {
        return view('livewire.requests.create', [
            'lockedDepartment' => $this->lockedDepartment(),
            'departments' => Department::query()->orderBy('name')->pluck('name', 'id'),
            'processes' => BusinessProcess::query()->orderBy('name')->get(),
            'skills' => Skill::query()->orderBy('name')->pluck('name', 'id'),
            'priorities' => RequesterPriority::options(),
            'impacts' => ImpactLevel::options(),
            'confidences' => EstimateConfidence::options(),
        ]);
    }
}
