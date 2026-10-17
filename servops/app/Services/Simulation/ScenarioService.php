<?php

namespace App\Services\Simulation;

use App\Data\Actor;
use App\Enums\ActorRole;
use App\Enums\CapacityEntryType;
use App\Enums\ImpactLevel;
use App\Enums\RequestStatus as S;
use App\Enums\ReworkCause;
use App\Enums\RiskLevel;
use App\Enums\Scenario;
use App\Exceptions\WorkflowException;
use App\Models\BusinessProcess;
use App\Models\Department;
use App\Models\Recommendation;
use App\Models\ScenarioRun;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\TeamMember;
use App\Services\Assignment\AssignmentRecommender;
use App\Services\Calendar\Clock;
use App\Services\Calendar\WorkingCalendar;
use App\Services\Capacity\CapacityEntryService;
use App\Services\Metrics\MetricsRecomputer;
use App\Services\Priority\PriorityOverrideService;
use App\Services\Workflow\RequestChangeService;
use App\Services\Workflow\RequestIntakeService;
use App\Services\Workflow\WorkflowTransitionService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

/**
 * Menjalankan skenario perubahan (BLUEPRINT §15) lewat service yang sama dengan pengguna:
 * guard, audit log, dan recompute tetap berlaku. Setiap run menyimpan snapshot sebelum/sesudah.
 */
class ScenarioService
{
    /** @var array<string, array{0: string, 1: bool}> label, apakah naik = memburuk */
    private const KPI_LABELS = [
        'at_risk' => ['Berisiko', true],
        'will_breach' => ['Akan terlambat / lewat', true],
        'backlog_hours' => ['Sisa effort (jam)', true],
        'done' => ['Selesai (diterima)', false],
        'rework_rate' => ['Rework rate (%)', true],
        'open_recommendations' => ['Rekomendasi terbuka', true],
    ];

    public function __construct(
        private Clock $clock,
        private WorkingCalendar $calendar,
        private WorkflowTransitionService $workflow,
        private RequestChangeService $changes,
        private RequestIntakeService $intake,
        private CapacityEntryService $capacityEntries,
        private PriorityOverrideService $overrides,
        private AssignmentRecommender $assignments,
        private MetricsRecomputer $recomputer,
    ) {}

    /**
     * @param  array<string, mixed>  $params
     *
     * @throws WorkflowException
     */
    public function run(Scenario $scenario, array $params, Actor $actor): ScenarioRun
    {
        $this->assertAllowed($scenario, $actor);

        $before = $this->snapshot();
        $this->apply($scenario, $params, $actor);
        $this->recomputer->run();
        $after = $this->snapshot();

        return ScenarioRun::create([
            'scenario' => $scenario->value,
            'params' => [...$params, 'actor' => $actor->label()],
            'before' => $before,
            'after' => $after,
            'created_at' => $this->clock->now(),
        ]);
    }

    /**
     * Dry-run: terapkan skenario di dalam transaksi, ambil diff, lalu rollback (termasuk jam simulasi).
     *
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     *
     * @throws WorkflowException
     */
    public function preview(Scenario $scenario, array $params, Actor $actor): array
    {
        $snapshots = $this->previewSnapshots($scenario, $params, $actor);

        return $this->diff($snapshots['before'], $snapshots['after']);
    }

    /**
     * Snapshot sebelum/sesudah dari dry-run (data skalar, aman disimpan di state komponen).
     *
     * @param  array<string, mixed>  $params
     * @return array{before: array<string, mixed>, after: array<string, mixed>}
     *
     * @throws WorkflowException
     */
    public function previewSnapshots(Scenario $scenario, array $params, Actor $actor): array
    {
        $this->assertAllowed($scenario, $actor);

        $before = $this->snapshot();
        $clockState = $this->clock->state();

        DB::beginTransaction();

        try {
            $this->apply($scenario, $params, $actor);
            $this->recomputer->run();
            $after = $this->snapshot();
        } finally {
            DB::rollBack();
            $this->clock->restore($clockState);
            $this->recomputer->flushCaches();
        }

        return ['before' => $before, 'after' => $after];
    }

    /** Majukan jam simulasi sejumlah jam kerja (tercatat sebagai run agar dampaknya terlihat). */
    public function advanceClock(float $workingHours, Actor $actor): ScenarioRun
    {
        return $this->run(Scenario::ClockAdvance, ['hours' => $workingHours], $actor);
    }

    /** Kembali ke waktu nyata. */
    public function releaseClock(): void
    {
        $this->clock->release();
        $this->recomputer->run();
    }

    /**
     * Kembalikan seluruh data ke kondisi awal (migrate:fresh --seed). Sesi login ikut terhapus.
     *
     * @throws WorkflowException
     */
    public function reset(): void
    {
        if (! config('servops.allow_reset')) {
            throw new WorkflowException('RESET_DISABLED', 'Reset data dinonaktifkan di environment ini (SERVOPS_ALLOW_RESET).');
        }

        Artisan::call('migrate:fresh', ['--seed' => true, '--force' => true]);
    }

    /**
     * Ringkasan perubahan antar dua snapshot.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @return array{clock: array{before: string, after: string}, kpis: array<string, array{label: string, before: float|int, after: float|int, delta: float|int, higher_is_worse: bool}>, requests: list<array<string, mixed>>, new_recommendations: list<string>, closed_recommendations: list<string>}
     */
    public function diff(array $before, array $after): array
    {
        $kpis = [];

        foreach (self::KPI_LABELS as $key => [$label, $higherIsWorse]) {
            $kpis[$key] = [
                'label' => $label,
                'before' => $before['kpis'][$key] ?? 0,
                'after' => $after['kpis'][$key] ?? 0,
                'delta' => round(($after['kpis'][$key] ?? 0) - ($before['kpis'][$key] ?? 0), 2),
                'higher_is_worse' => $higherIsWorse,
            ];
        }

        $requests = [];

        foreach ($after['requests'] as $code => $state) {
            $old = $before['requests'][$code] ?? null;

            if ($old !== null && $old['rank'] === $state['rank'] && $old['risk'] === $state['risk'] && $old['finish'] === $state['finish'] && $old['status'] === $state['status']) {
                continue;
            }

            $riskBefore = RiskLevel::tryFrom((string) ($old['risk'] ?? ''));
            $riskAfter = RiskLevel::tryFrom((string) $state['risk']);

            $requests[] = [
                'code' => $code,
                'title' => $state['title'],
                'is_new' => $old === null,
                'rank_before' => $old['rank'] ?? null,
                'rank_after' => $state['rank'],
                'risk_before' => $riskBefore,
                'risk_after' => $riskAfter,
                'status_before' => S::tryFrom((string) ($old['status'] ?? '')),
                'status_after' => S::tryFrom((string) $state['status']),
                'finish_before' => $old['finish'] ?? null,
                'finish_after' => $state['finish'],
                'worse' => ($riskAfter?->severity() ?? 0) > ($riskBefore?->severity() ?? 0),
                'better' => ($riskAfter?->severity() ?? 0) < ($riskBefore?->severity() ?? 0),
            ];
        }

        foreach (array_diff_key($before['requests'], $after['requests']) as $code => $state) {
            $requests[] = [
                'code' => $code, 'title' => $state['title'], 'is_new' => false,
                'rank_before' => $state['rank'], 'rank_after' => null,
                'risk_before' => RiskLevel::tryFrom((string) $state['risk']), 'risk_after' => null,
                'status_before' => S::tryFrom((string) $state['status']), 'status_after' => null,
                'finish_before' => $state['finish'], 'finish_after' => null, 'worse' => false, 'better' => false,
            ];
        }

        usort($requests, fn (array $a, array $b) => [$b['worse'], $a['rank_after'] ?? PHP_INT_MAX] <=> [$a['worse'], $b['rank_after'] ?? PHP_INT_MAX]);

        return [
            'clock' => ['before' => $before['at'], 'after' => $after['at']],
            'kpis' => $kpis,
            'requests' => $requests,
            'new_recommendations' => array_values(array_diff_key($after['recommendations'], $before['recommendations'])),
            'closed_recommendations' => array_values(array_diff_key($before['recommendations'], $after['recommendations'])),
        ];
    }

    /**
     * @return array{at: string, requests: array<string, array<string, mixed>>, kpis: array<string, float|int>, recommendations: array<string, string>}
     */
    public function snapshot(): array
    {
        $open = ServiceRequest::query()->open()->where('status', '!=', S::New)->with('assignee')->get();
        $delivered = ServiceRequest::query()->whereIn('status', [S::Delivered, S::Accepted, S::Returned])->get(['rework_count']);

        return [
            'at' => $this->clock->now()->toIso8601String(),
            'requests' => $open->mapWithKeys(fn (ServiceRequest $request) => [$request->code => [
                'title' => $request->title,
                'rank' => $request->priority_rank,
                'score' => $request->priority_score,
                'risk' => $request->risk_level?->value,
                'status' => $request->status->value,
                'finish' => $request->projected_finish_at?->format('Y-m-d H:i'),
                'assignee' => $request->assignee?->name,
            ]])->all(),
            'kpis' => [
                'at_risk' => $open->filter(fn (ServiceRequest $request) => ($request->risk_level?->severity() ?? 0) > 0)->count(),
                'will_breach' => $open->filter(fn (ServiceRequest $request) => ($request->risk_level?->severity() ?? 0) >= RiskLevel::WillBreach->severity())->count(),
                'backlog_hours' => round((float) $open->filter(fn (ServiceRequest $request) => $request->status->countsAsLoad())->sum('remaining_hours'), 1),
                'done' => ServiceRequest::done()->count(),
                'rework_rate' => $delivered->isEmpty() ? 0 : round($delivered->where('rework_count', '>', 0)->count() / $delivered->count() * 100, 1),
                'open_recommendations' => Recommendation::query()->open()->count(),
            ],
            'recommendations' => Recommendation::query()->open()->pluck('title', 'fingerprint')->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     *
     * @throws WorkflowException
     */
    private function apply(Scenario $scenario, array $params, Actor $actor): void
    {
        match ($scenario) {
            Scenario::ImpactEscalation => $this->escalateImpact($params, $actor),
            Scenario::MemberUnavailable => $this->memberUnavailable($params),
            Scenario::DeliveryReturned => $this->deliverThenReturn($params, $actor),
            Scenario::DependencyDelay => $this->delayDependency($params, $actor),
            Scenario::UrgentInsert => $this->insertUrgent($actor),
            Scenario::EstimateGrowth => $this->growEstimate($params, $actor),
            Scenario::ClockAdvance => $this->moveClock((float) ($params['hours'] ?? 8)),
        };
    }

    /** @param array<string, mixed> $params */
    private function escalateImpact(array $params, Actor $actor): void
    {
        $request = $this->request($params['code'] ?? 'SR-107');

        $this->changes->assessImpact(
            $request, ImpactLevel::Critical, 4, 120,
            '[Simulasi S1] Selisih data memengaruhi rekonsiliasi & laporan 4 departemen.', $actor,
        );
    }

    /** @param array<string, mixed> $params */
    private function memberUnavailable(array $params): void
    {
        $member = TeamMember::query()->findOrFail($params['member_id'] ?? TeamMember::query()->where('name', 'Citra')->value('id'));
        $days = max(1, (int) ($params['days'] ?? 2));
        $now = $this->clock->now();

        $this->capacityEntries->add(
            $member, CapacityEntryType::Sick,
            $now->startOfDay(), $this->calendar->endOfWorkingDay($now, $days - 1)->endOfDay(),
            null, "[Simulasi S2] Sakit {$days} hari kerja",
        );
    }

    /**
     * Dorong request sampai "diserahkan" lalu dikembalikan pemohon.
     *
     * @param  array<string, mixed>  $params
     */
    private function deliverThenReturn(array $params, Actor $actor): void
    {
        $request = $this->request($params['code'] ?? 'SR-101');
        $reason = '[Simulasi S3]';

        for ($guard = 0; $request->status !== S::Delivered && $guard < 6; $guard++) {
            $result = match ($request->status) {
                S::Triage => $this->workflow->transition($request, S::Ready, $actor, "{$reason} Triage selesai"),
                S::Ready => $this->workflow->transition($request, S::Assigned, $actor, "{$reason} Ditugaskan", [
                    'assignee_id' => $this->assignments->best($request)?->member->id
                        ?? throw new WorkflowException('SCENARIO_NOT_APPLICABLE', "Tidak ada anggota kompeten untuk {$request->code}."),
                ]),
                S::Assigned, S::Returned => $this->workflow->transition($request, S::InProgress, $actor, "{$reason} Mulai dikerjakan"),
                S::InProgress => $this->workflow->transition($request, S::Delivered, $actor, "{$reason} Diserahkan ke pemohon"),
                default => throw new WorkflowException('SCENARIO_NOT_APPLICABLE', "{$request->code} berstatus \"{$request->status->label()}\" — tidak bisa disimulasikan diserahkan & dikembalikan."),
            };
            $request = $result->request;
        }

        $this->workflow->transition($request, S::Returned, $actor, "{$reason} Hasil tidak sesuai kebutuhan pemohon", [
            'rework_estimate_hours' => (float) ($params['rework_hours'] ?? 3),
            'rework_cause' => ReworkCause::UnclearScope,
        ]);
    }

    /** @param array<string, mixed> $params */
    private function delayDependency(array $params, Actor $actor): void
    {
        $request = $this->request($params['code'] ?? 'SR-105');
        $dependency = $request->openDependencies()->first()
            ?? throw new WorkflowException('SCENARIO_NOT_APPLICABLE', "{$request->code} tidak punya dependensi terbuka.");

        $newEta = $this->calendar->addWorkingHours($dependency->expected_at ?? $this->clock->now(), (float) ($params['eta_delay_hours'] ?? 8));
        $this->changes->rescheduleDependency($dependency, $newEta, '[Simulasi S4] Tim Finance mengabarkan data terlambat.', $actor);

        $this->moveClock((float) ($params['hours'] ?? 6));
    }

    private function insertUrgent(Actor $actor): void
    {
        $request = $this->intake->create([
            'title' => 'Audit stok mendadak permintaan direksi',
            'description' => '[Simulasi S5] Direksi meminta audit selisih stok gudang sebelum rapat pemegang saham.',
            'category' => 'investigation',
            'requester_department_id' => Department::query()->where('code', 'MGT')->value('id'),
            'business_process_id' => BusinessProcess::query()->where('name', 'Order-to-cash')->value('id'),
            'requester_priority' => 'high',
            'impact_level' => ImpactLevel::High->value,
            'affected_departments' => 2,
            'required_skill_id' => Skill::query()->where('code', 'data_analysis')->value('id'),
            'required_level' => 2,
            'estimate_hours' => 10,
            'deadline_at' => $this->calendar->endOfWorkingDay($this->clock->now(), 1),
        ], $actor);

        $request = $this->workflow->transition($request, S::Ready, $actor, '[Simulasi S5] Siap dikerjakan')->request;
        $this->overrides->apply($request, 1, '[Simulasi S5] Permintaan direksi, tanpa tambahan kapasitas.', $actor);
    }

    /** @param array<string, mixed> $params */
    private function growEstimate(array $params, Actor $actor): void
    {
        $this->changes->reviseEstimate(
            $this->request($params['code'] ?? 'SR-103'),
            (float) ($params['hours'] ?? 20),
            '[Simulasi S6] Analisis awal: sumber data & format laporan lebih banyak dari perkiraan.',
            $actor,
            scopeChange: true,
        );
    }

    private function moveClock(float $workingHours): void
    {
        $this->clock->freeze($this->calendar->addWorkingHours($this->clock->now(), max(0.25, $workingHours)));
        $this->recomputer->recomputeIfEnabled();
    }

    private function request(string $code): ServiceRequest
    {
        return ServiceRequest::query()->where('code', $code)->first()
            ?? throw new WorkflowException('SCENARIO_NOT_APPLICABLE', "Request {$code} tidak ditemukan.");
    }

    private function assertAllowed(Scenario $scenario, Actor $actor): void
    {
        if ($scenario->requiresManager() && $actor->role !== ActorRole::Manager && $actor->role !== ActorRole::System) {
            throw new WorkflowException('SCENARIO_ROLE', 'Skenario ini membutuhkan peran Manager karena melibatkan override prioritas.');
        }
    }
}
