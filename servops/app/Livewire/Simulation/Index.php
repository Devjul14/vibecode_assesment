<?php

namespace App\Livewire\Simulation;

use App\Enums\ActorRole;
use App\Enums\RequestStatus;
use App\Enums\Scenario;
use App\Livewire\Concerns\InteractsWithServOps;
use App\Models\ScenarioRun;
use App\Models\ServiceRequest;
use App\Models\TeamMember;
use App\Services\Calendar\Clock;
use App\Services\Simulation\ScenarioService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Simulasi skenario perubahan: preview tanpa efek samping, jalankan sungguhan, geser jam, reset.
 */
#[Title('Simulasi')]
class Index extends Component
{
    use InteractsWithServOps;

    // Parameter skenario
    public ?int $memberId = null;

    public int $days = 2;

    public string $returnCode = 'SR-101';

    public float $reworkHours = 3;

    public float $etaDelayHours = 8;

    public float $delayClockHours = 6;

    public float $growthHours = 20;

    public ?int $selectedRunId = null;

    /** @var array{scenario: string, before: array<string, mixed>, after: array<string, mixed>}|null */
    public ?array $preview = null;

    public function mount(): void
    {
        $this->memberId = TeamMember::query()->where('name', 'Citra')->value('id');
    }

    public function previewScenario(string $scenario, ScenarioService $service): void
    {
        $type = Scenario::from($scenario);

        $this->attempt(function () use ($service, $type) {
            Gate::authorize('simulation.run');
            $this->preview = ['scenario' => $type->value, ...$service->previewSnapshots($type, $this->paramsFor($type), $this->actor())];
            $this->selectedRunId = null;
        }, "Preview {$type->code()} — belum disimpan");
    }

    public function runScenario(string $scenario, ScenarioService $service): void
    {
        $type = Scenario::from($scenario);

        $this->attempt(function () use ($service, $type) {
            Gate::authorize('simulation.run');
            $this->selectedRunId = $service->run($type, $this->paramsFor($type), $this->actor())->id;
            $this->preview = null;
        }, "{$type->code()} dijalankan: {$type->label()}");
    }

    public function advance(float $hours, ScenarioService $service): void
    {
        $this->attempt(function () use ($service, $hours) {
            Gate::authorize('simulation.run');
            $this->selectedRunId = $service->advanceClock($hours, $this->actor())->id;
            $this->preview = null;
        }, "Jam simulasi maju {$hours} jam kerja");
    }

    public function release(ScenarioService $service): void
    {
        $this->attempt(function () use ($service) {
            Gate::authorize('simulation.run');
            $service->releaseClock();
        }, 'Kembali ke waktu nyata');
    }

    public function resetData(ScenarioService $service): void
    {
        $ok = $this->attempt(function () use ($service) {
            Gate::authorize('simulation.run');
            $service->reset();
        }, 'Data dikembalikan ke kondisi awal');

        if ($ok) {
            $this->redirectRoute('login');
        }
    }

    public function showRun(int $id): void
    {
        $this->selectedRunId = $id;
        $this->preview = null;
    }

    /**
     * @return array<string, mixed>
     */
    private function paramsFor(Scenario $scenario): array
    {
        return match ($scenario) {
            Scenario::MemberUnavailable => ['member_id' => $this->memberId, 'days' => max(1, $this->days)],
            Scenario::DeliveryReturned => ['code' => $this->returnCode, 'rework_hours' => $this->reworkHours],
            Scenario::DependencyDelay => ['eta_delay_hours' => $this->etaDelayHours, 'hours' => $this->delayClockHours],
            Scenario::EstimateGrowth => ['hours' => $this->growthHours],
            default => [],
        };
    }

    public function render(ScenarioService $service, Clock $clock): View
    {
        $runs = ScenarioRun::query()->latest('id')->limit(15)->get();
        $selected = $this->selectedRunId ? $runs->firstWhere('id', $this->selectedRunId) ?? ScenarioRun::query()->find($this->selectedRunId) : null;

        $result = match (true) {
            $this->preview !== null => [
                'mode' => 'preview',
                'scenario' => Scenario::from($this->preview['scenario']),
                'diff' => $service->diff($this->preview['before'], $this->preview['after']),
                'at' => null,
                'actor' => null,
            ],
            $selected !== null => [
                'mode' => 'run',
                'scenario' => $selected->scenarioType(),
                'diff' => $service->diff($selected->before, $selected->after),
                'at' => $selected->created_at,
                'actor' => $selected->params['actor'] ?? null,
            ],
            default => null,
        };

        return view('livewire.simulation.index', [
            'catalog' => Scenario::catalog(),
            'members' => TeamMember::query()->active()->orderBy('name')->get(),
            'returnable' => ServiceRequest::query()->whereIn('status', [RequestStatus::Triage, RequestStatus::Ready, RequestStatus::Assigned, RequestStatus::InProgress, RequestStatus::Returned])
                ->whereNotNull('estimate_hours')->orderBy('code')->get(['code', 'title']),
            'runs' => $runs,
            'result' => $result,
            'now' => $clock->now(),
            'simulated' => $clock->isSimulated(),
            'isManager' => auth()->user()->role === ActorRole::Manager,
            'allowReset' => (bool) config('servops.allow_reset'),
        ]);
    }
}
