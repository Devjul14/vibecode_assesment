<?php

namespace App\Livewire\Requests;

use App\Enums\DependencyType;
use App\Enums\ImpactLevel;
use App\Enums\RecommendationStatus;
use App\Enums\RequestStatus;
use App\Enums\ReworkCause;
use App\Livewire\Concerns\InteractsWithServOps;
use App\Models\PriorityOverride;
use App\Models\Recommendation;
use App\Models\ServiceRequest;
use App\Models\TeamMember;
use App\Services\Assignment\AssignmentRecommender;
use App\Services\Capacity\CapacityService;
use App\Services\Estimation\EstimateAdvisor;
use App\Services\Priority\PriorityOverrideService;
use App\Services\Recommendation\RecommendationService;
use App\Services\Workflow\HistoryRecorder;
use App\Services\Workflow\RequestChangeService;
use App\Services\Workflow\WorkflowTransitionService;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * Detail request: mengapa prioritasnya begini, seberapa berisiko, siapa yang cocok,
 * dan semua aksi workflow (dengan guard yang sama seperti di service).
 */
class Show extends Component
{
    use InteractsWithServOps;

    public ServiceRequest $serviceRequest;

    // Ubah status
    public string $target = '';

    public string $reason = '';

    public ?int $assigneeId = null;

    public ?int $pairingMemberId = null;

    public string $overrideReason = '';

    public ?string $reworkHours = null;

    public string $reworkCause = '';

    // Dependensi (dipakai saat blokir maupun tambah dependensi)
    public string $depParty = '';

    public string $depDescription = '';

    public string $depEta = '';

    public ?int $depOnRequestId = null;

    // Revisi estimasi
    public ?string $newEstimate = null;

    public string $estimateReason = '';

    public bool $scopeChange = false;

    // Reassign
    public ?int $reassignTo = null;

    public string $reassignReason = '';

    // Triage dampak
    public string $impactLevel = '';

    public ?int $impactDepartments = null;

    public ?int $impactUsers = null;

    public string $impactReason = '';

    // Override prioritas
    public int $overrideRank = 1;

    public string $overrideJustification = '';

    /** @var list<array<string, mixed>>|null */
    public ?array $preview = null;

    public function mount(ServiceRequest $serviceRequest): void
    {
        $this->authorize('view', $serviceRequest);
        $this->serviceRequest = $serviceRequest;
        $this->notice = session('notice');
    }

    public function changeStatus(WorkflowTransitionService $workflow): void
    {
        $target = RequestStatus::tryFrom($this->target);

        if ($target === null) {
            $this->notice = ['type' => 'error', 'message' => 'Pilih status tujuan.'];

            return;
        }

        $payload = array_filter([
            'assignee_id' => $this->assigneeId,
            'pairing_member_id' => $this->pairingMemberId,
            'override_reason' => $this->overrideReason ?: null,
            'rework_estimate_hours' => $this->reworkHours !== null && $this->reworkHours !== '' ? (float) $this->reworkHours : null,
            'rework_cause' => $this->reworkCause ?: null,
            'dependency' => $target === RequestStatus::Blocked ? $this->dependencyPayload() : null,
        ], fn ($value) => $value !== null && $value !== []);

        if ($this->attempt(
            fn () => $this->authorizeThen(['transition', [$this->serviceRequest, $target]], fn () => $workflow->transition($this->serviceRequest, $target, $this->actor(), $this->reason ?: null, $payload)),
            "{$this->serviceRequest->code} → {$target->label()}",
        )) {
            $this->reset('target', 'reason', 'assigneeId', 'pairingMemberId', 'overrideReason', 'reworkHours', 'reworkCause', 'depParty', 'depDescription', 'depEta', 'depOnRequestId');
        }

        $this->serviceRequest->refresh();
    }

    public function assignTo(int $memberId, WorkflowTransitionService $workflow, RequestChangeService $changes): void
    {
        $member = TeamMember::query()->findOrFail($memberId);

        if ($this->serviceRequest->status === RequestStatus::Ready) {
            $this->attempt(
                fn () => $this->authorizeThen(['assign', $this->serviceRequest], fn () => $workflow->transition($this->serviceRequest, RequestStatus::Assigned, $this->actor(), "Ditugaskan ke {$member->name}", ['assignee_id' => $member->id])),
                "{$this->serviceRequest->code} ditugaskan ke {$member->name}",
            );
        } else {
            $this->attempt(
                fn () => $this->authorizeThen(['assign', $this->serviceRequest], fn () => $changes->reassign($this->serviceRequest, $member, $this->reassignReason ?: "Dipindahkan ke {$member->name} (kandidat sistem)", $this->actor())),
                "{$this->serviceRequest->code} dipindahkan ke {$member->name}",
            );
        }

        $this->serviceRequest->refresh();
    }

    public function reassign(RequestChangeService $changes): void
    {
        $member = TeamMember::query()->find($this->reassignTo);

        if ($member === null) {
            $this->notice = ['type' => 'error', 'message' => 'Pilih anggota tujuan.'];

            return;
        }

        if ($this->attempt(
            fn () => $this->authorizeThen(['assign', $this->serviceRequest], fn () => $changes->reassign($this->serviceRequest, $member, $this->reassignReason, $this->actor(), $this->pairingMemberId, $this->overrideReason ?: null)),
            "{$this->serviceRequest->code} dipindahkan ke {$member->name}",
        )) {
            $this->reset('reassignTo', 'reassignReason', 'pairingMemberId', 'overrideReason');
        }

        $this->serviceRequest->refresh();
    }

    public function reviseEstimate(RequestChangeService $changes): void
    {
        $this->validate(['newEstimate' => ['required', 'numeric', 'gt:0', 'max:500']]);

        if ($this->attempt(
            fn () => $this->authorizeThen(['reviseEstimate', $this->serviceRequest], fn () => $changes->reviseEstimate($this->serviceRequest, (float) $this->newEstimate, $this->estimateReason, $this->actor(), $this->scopeChange)),
            "Estimasi {$this->serviceRequest->code} diperbarui menjadi {$this->newEstimate} jam",
        )) {
            $this->reset('newEstimate', 'estimateReason', 'scopeChange');
        }

        $this->serviceRequest->refresh();
    }

    public function addDependency(RequestChangeService $changes): void
    {
        $payload = $this->dependencyPayload();

        if ($payload === []) {
            $this->notice = ['type' => 'error', 'message' => 'Isi pihak eksternal atau pilih request yang ditunggu.'];

            return;
        }

        if ($this->attempt(fn () => $this->authorizeThen(['manageDependencies', $this->serviceRequest], fn () => $changes->addDependency($this->serviceRequest, $payload, $this->actor())), 'Dependensi ditambahkan')) {
            $this->reset('depParty', 'depDescription', 'depEta', 'depOnRequestId');
        }

        $this->serviceRequest->refresh();
    }

    public function resolveDependency(int $id, RequestChangeService $changes): void
    {
        $dependency = $this->serviceRequest->dependencies()->findOrFail($id);
        $this->attempt(fn () => $this->authorizeThen(['manageDependencies', $this->serviceRequest], fn () => $changes->resolveDependency($dependency, $this->actor(), 'Ditandai selesai')), 'Dependensi ditandai selesai');
        $this->serviceRequest->refresh();
    }

    public function assessImpact(RequestChangeService $changes): void
    {
        $this->validate([
            'impactLevel' => ['required', Rule::enum(ImpactLevel::class)],
            'impactDepartments' => ['nullable', 'integer', 'min:0', 'max:50'],
            'impactUsers' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($this->attempt(
            fn () => $this->authorizeThen(['assessImpact', $this->serviceRequest], fn () => $changes->assessImpact(
                $this->serviceRequest, ImpactLevel::from($this->impactLevel), $this->impactDepartments, $this->impactUsers, $this->impactReason, $this->actor(),
            )),
            "Dampak {$this->serviceRequest->code} dinilai: ".ImpactLevel::from($this->impactLevel)->label(),
        )) {
            $this->reset('impactLevel', 'impactDepartments', 'impactUsers', 'impactReason');
        }

        $this->serviceRequest->refresh();
    }

    public function previewOverride(PriorityOverrideService $overrides): void
    {
        $this->preview = null;
        $this->attempt(function () use ($overrides) {
            $this->authorize('override', $this->serviceRequest);
            $this->preview = $overrides->preview($this->serviceRequest, $this->overrideRank, $this->overrideJustification, $this->actor());
        }, 'Preview dampak override (belum disimpan)');
    }

    public function applyOverride(PriorityOverrideService $overrides): void
    {
        if ($this->attempt(
            fn () => $this->authorizeThen(['override', $this->serviceRequest], fn () => $overrides->apply($this->serviceRequest, $this->overrideRank, $this->overrideJustification, $this->actor())),
            "Override disimpan: {$this->serviceRequest->code} dipaksa ke peringkat {$this->overrideRank}",
        )) {
            $this->reset('overrideJustification', 'preview');
            $this->overrideRank = 1;
        }

        $this->serviceRequest->refresh();
    }

    public function revokeOverride(int $id, PriorityOverrideService $overrides): void
    {
        $override = PriorityOverride::query()->where('service_request_id', $this->serviceRequest->id)->findOrFail($id);
        $this->attempt(fn () => $this->authorizeThen(['override', $this->serviceRequest], fn () => $overrides->revoke($override, $this->actor(), 'Override dicabut')), 'Override dicabut');
        $this->serviceRequest->refresh();
    }

    public function acceptRecommendation(int $id, RecommendationService $service): void
    {
        $recommendation = $this->serviceRequest->recommendations()->findOrFail($id);
        $this->attempt(fn () => $this->authorizeThen(['recommendations.decide', []], fn () => $service->accept($recommendation, $this->actor())), "Rekomendasi diterima: {$recommendation->title}");
        $this->serviceRequest->refresh();
    }

    public function dismissRecommendation(int $id, RecommendationService $service): void
    {
        $recommendation = $this->serviceRequest->recommendations()->findOrFail($id);
        $this->attempt(fn () => $this->authorizeThen(['recommendations.decide', []], fn () => $service->dismiss($recommendation, $this->actor())), 'Rekomendasi diabaikan');
    }

    /**
     * Otorisasi (policy/gate) lalu jalankan aksi. Penolakan ditangani attempt() sebagai pesan.
     *
     * @param  array{0: string, 1: mixed}  $ability
     */
    private function authorizeThen(array $ability, Closure $action): mixed
    {
        $this->authorize($ability[0], $ability[1]);

        return $action();
    }

    /**
     * @return array<string, mixed>
     */
    private function dependencyPayload(): array
    {
        if ($this->depParty === '' && $this->depOnRequestId === null) {
            return [];
        }

        return [
            'type' => $this->depOnRequestId ? DependencyType::Internal : DependencyType::External,
            'depends_on_request_id' => $this->depOnRequestId,
            'external_party' => $this->depParty ?: null,
            'description' => $this->depDescription ?: null,
            'expected_at' => $this->depEta ?: null,
        ];
    }

    public function render(AssignmentRecommender $recommender, CapacityService $capacity, EstimateAdvisor $estimates, HistoryRecorder $historyRecorder): View
    {
        $request = $this->serviceRequest->load([
            'assignee', 'pairingMember', 'requiredSkill', 'requesterDepartment', 'businessProcess', 'parent',
            'dependencies.dependsOn', 'openDependencies', 'estimateRevisions', 'timeLogs.member',
        ]);

        $user = auth()->user();
        $capacity->flush();
        $recommender->flush();
        $ranks = ServiceRequest::query()->whereNotNull('priority_rank')->pluck('priority_rank', 'id')->all();
        $isOpen = $request->status->isOpen() && $request->status !== RequestStatus::Delivered;

        return view('livewire.requests.show', [
            'request' => $request,
            'transitions' => array_values(array_filter(
                $request->status->allowedTransitions(),
                fn (RequestStatus $status) => $status !== RequestStatus::Assigned && $user->can('transition', [$request, $status]),
            )),
            'canAssign' => $request->status->canTransitionTo(RequestStatus::Assigned) && $user->can('assign', $request),
            'canReassign' => in_array($request->status, [RequestStatus::Assigned, RequestStatus::InProgress, RequestStatus::Blocked, RequestStatus::Returned], true) && $user->can('assign', $request),
            'candidates' => $isOpen ? $recommender->candidates($request, $ranks) : collect(),
            'pairingOptions' => $isOpen ? $recommender->pairingOptions($request, $ranks) : collect(),
            'members' => TeamMember::query()->active()->orderBy('name')->get(),
            'history' => $request->statusHistories()->get()->reverse()->values(),
            'activeOverride' => PriorityOverride::query()->where('service_request_id', $request->id)->whereNull('revoked_at')->latest('id')->first(),
            'recommendations' => Recommendation::query()->where('service_request_id', $request->id)->where('status', RecommendationStatus::Open)->orderByDesc('severity')->get(),
            'otherRequests' => ServiceRequest::query()->open()->whereKeyNot($request->id)->orderBy('code')->get(['id', 'code', 'title']),
            'reworkCauses' => ReworkCause::options(),
            'impactLevels' => array_diff_key(ImpactLevel::options(), [ImpactLevel::Unknown->value => true]),
            'planning' => $estimates->planningHours($request),
            'waitHours' => $historyRecorder->currentWaitHours($request),
        ])->title("{$request->code} · {$request->title}");
    }
}
