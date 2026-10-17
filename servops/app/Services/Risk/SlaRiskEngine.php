<?php

namespace App\Services\Risk;

use App\Data\AssignmentCandidate;
use App\Data\Reason;
use App\Data\RiskResult;
use App\Enums\RequestStatus;
use App\Enums\RiskLevel;
use App\Models\RequestDependency;
use App\Models\ServiceRequest;
use App\Models\TeamMember;
use App\Services\Assignment\AssignmentRecommender;
use App\Services\Calendar\Clock;
use App\Services\Calendar\WorkingCalendar;
use App\Services\Capacity\CapacityService;
use App\Services\Estimation\EstimateAdvisor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Risiko SLA berbasis proyeksi antrean (BLUEPRINT §9): risiko terlihat sebelum tenggat terlewati,
 * meskipun status pekerjaan masih terlihat normal.
 */
class SlaRiskEngine
{
    /** Pemicu yang menaikkan level minimal menjadi "berisiko". */
    private const ESCALATING = [
        'BLOCKED_NEAR_LATEST_START', 'EXTERNAL_ETA_LATE', 'DEPENDENCY_NO_ETA', 'UNASSIGNED_LOW_SLACK',
        'ASSIGNEE_UNAVAILABLE', 'SCOPE_UNCLEAR', 'ESTIMATE_GROWTH', 'SCARCE_SKILL', 'NO_QUALIFIED_CAPACITY',
    ];

    /** @var array<string, int> */
    private array $qualifiedCounts = [];

    public function __construct(
        private Clock $clock,
        private WorkingCalendar $calendar,
        private CapacityService $capacity,
        private AssignmentRecommender $assignments,
        private EstimateAdvisor $estimates,
    ) {}

    /**
     * @param  Collection<int, ServiceRequest>  $requests
     * @param  array<int, int|null>  $ranks  request_id => priority_rank
     * @return array<int, RiskResult>
     */
    public function evaluateAll(Collection $requests, array $ranks): array
    {
        $results = [];

        foreach ($requests as $request) {
            $results[$request->id] = $this->evaluate($request, $ranks);
        }

        return $results;
    }

    /**
     * @param  array<int, int|null>  $ranks
     */
    public function evaluate(ServiceRequest $request, array $ranks): RiskResult
    {
        if ($request->status === RequestStatus::Delivered) {
            return $this->evaluateDelivered($request);
        }

        $now = $this->clock->now();
        $deadline = CarbonImmutable::instance($request->deadline_at);
        $planning = $this->estimates->planningHours($request);
        $planned = $planning['hours'];
        $hoursLeft = $this->calendar->workingHoursBetween($now, $deadline);
        $slack = round($hoursLeft - $planned, 2);
        $reasons = [];

        [$projected, $member, $queue] = $this->project($request, $ranks, $planned, $reasons);
        $latestStart = $this->latestStart($deadline, $planned, $member);

        $this->dependencyReasons($request, $now, $latestStart, $reasons);
        $this->stateReasons($request, $planning, $hoursLeft, $slack, $member, $projected, $deadline, $now, $reasons);

        $level = $this->level($now, $deadline, $projected, $planned, $reasons);

        if ($level->severity() >= RiskLevel::WillBreach->severity() && $member !== null && $queue > 0 && $request->assignee_id === $member->id) {
            array_unshift($reasons, new Reason(
                'QUEUE_OVERLOAD',
                "Antrean {$member->name} ({$queue} jam prioritas lebih tinggi) membuat perkiraan selesai melewati tenggat.",
                'Pindahkan penugasan atau seimbangkan beban',
            ));
        }

        return new RiskResult(
            requestId: $request->id,
            level: $level,
            reasons: $reasons,
            slackHours: $slack,
            latestStartAt: $latestStart,
            projectedFinishAt: $projected,
            plannedHours: $planned,
            projectedAssigneeId: $member?->id,
        );
    }

    /**
     * Proyeksi selesai. Request tanpa assignee memakai kandidat terbaik.
     *
     * @param  array<int, int|null>  $ranks
     * @param  list<Reason>  $reasons
     * @return array{0: CarbonImmutable|null, 1: TeamMember|null, 2: float}
     */
    private function project(ServiceRequest $request, array $ranks, float $planned, array &$reasons): array
    {
        $eta = $this->dependencyEta($request);

        if ($request->assignee) {
            $queue = $this->capacity->queueAheadHours($request->assignee, $request, $ranks);

            return [$this->capacity->projectFinish($request->assignee, $queue, $planned, $eta), $request->assignee, $queue];
        }

        $best = $this->assignments->best($request, $ranks);

        if (! $best instanceof AssignmentCandidate) {
            $reasons[] = new Reason(
                'NO_QUALIFIED_CAPACITY',
                'Tidak ada anggota kompeten yang tersedia untuk pekerjaan ini.',
                'Pairing dengan mentor, negosiasi tenggat, atau tambah kapasitas',
            );

            return [null, null, 0.0];
        }

        return [$best->projectedFinishAt, $best->member, $best->queueAheadHours];
    }

    /** Mulai paling lambat agar selesai tepat tenggat, dengan laju kapasitas efektif orang yang mengerjakan. */
    private function latestStart(CarbonImmutable $deadline, float $planned, ?TeamMember $member): CarbonImmutable
    {
        $dailyEffective = $member?->baseEffectiveDailyHours() ?: $this->calendar->hoursPerDay() * (float) config('servops.priority.effective_ratio');
        $calendarHours = $planned * $this->calendar->hoursPerDay() / max(0.5, $dailyEffective);

        return $this->calendar->subWorkingHours($deadline, $calendarHours);
    }

    /**
     * @param  list<Reason>  $reasons
     */
    private function dependencyReasons(ServiceRequest $request, CarbonImmutable $now, CarbonImmutable $latestStart, array &$reasons): void
    {
        $open = $request->openDependencies;

        if ($open->isEmpty()) {
            return;
        }

        foreach ($open as $dependency) {
            $party = $dependency->external_party ?? $dependency->dependsOn?->code ?? 'pihak lain';

            if ($dependency->expected_at === null) {
                $reasons[] = new Reason('DEPENDENCY_NO_ETA', "Menunggu {$party} tanpa perkiraan waktu.", "Minta ETA dari {$party}");

                continue;
            }

            if ($dependency->expected_at->greaterThan($latestStart)) {
                $reasons[] = new Reason(
                    'EXTERNAL_ETA_LATE',
                    "ETA {$party} ({$dependency->expected_at->translatedFormat('D d M H:i')}) melewati batas mulai paling lambat ({$latestStart->translatedFormat('D d M H:i')}).",
                    "Eskalasi ke {$party} sekarang; siapkan pekerjaan parsial",
                );
            } else {
                $reasons[] = new Reason(
                    'DEPENDENCY_WAIT',
                    "Menunggu {$party}: {$dependency->description} (ETA {$dependency->expected_at->translatedFormat('D d M H:i')}).",
                    "Pantau & konfirmasi ETA dengan {$party}",
                );
            }
        }

        $warningHours = (float) config('servops.risk.blocked_warning_hours');

        if ($request->status === RequestStatus::Blocked && $now->greaterThanOrEqualTo($this->calendar->subWorkingHours($latestStart, $warningHours))) {
            $reasons[] = new Reason(
                'BLOCKED_NEAR_LATEST_START',
                'Masih terblokir padahal batas mulai paling lambat tinggal '.max(0, $this->calendar->workingHoursBetween($now, $latestStart)).' jam kerja.',
                'Eskalasi dependensi; negosiasi tenggat',
            );
        }
    }

    /**
     * @param  array{hours: float, assumed: bool, source: string}  $planning
     * @param  list<Reason>  $reasons
     */
    private function stateReasons(
        ServiceRequest $request,
        array $planning,
        float $hoursLeft,
        float $slack,
        ?TeamMember $member,
        ?CarbonImmutable $projected,
        CarbonImmutable $deadline,
        CarbonImmutable $now,
        array &$reasons,
    ): void {
        if ($planning['assumed'] || $request->status === RequestStatus::NeedsClarification) {
            $reasons[] = new Reason('SCOPE_UNCLEAR', 'Scope belum jelas. '.$planning['source'].'.', 'Sesi klarifikasi ber-timebox, lalu isi estimasi');
        }

        if ($request->assignee_id === null && in_array($request->status, [RequestStatus::Ready, RequestStatus::Triage, RequestStatus::New], true)
            && $slack < (float) config('servops.risk.unassigned_low_slack_hours')) {
            $reasons[] = new Reason('UNASSIGNED_LOW_SLACK', "Belum ditugaskan dan slack tinggal {$slack} jam kerja.", 'Tugaskan segera');
        }

        if ($request->assignee) {
            $absence = $this->capacity->absenceBetween($request->assignee, $now, $projected?->max($now->addMinute()) ?? $deadline);

            if ($absence !== null) {
                $reasons[] = new Reason(
                    'ASSIGNEE_UNAVAILABLE',
                    "{$request->assignee->name} {$absence->type->label()} ({$absence->starts_at->translatedFormat('D d M')}–{$absence->ends_at->translatedFormat('D d M')}).",
                    'Pindahkan ke anggota kompeten lain',
                );
            }
        }

        $growth = $request->scopeGrowthPct();

        if ($growth !== null && $growth > (float) config('servops.risk.estimate_growth_pct')) {
            $reasons[] = new Reason('ESTIMATE_GROWTH', "Estimasi naik {$growth}% karena perubahan scope.", 'Re-baseline tenggat atau pecah scope');
        }

        if ($request->rework_count > 0) {
            $reasons[] = new Reason('REWORK', "Sudah dikembalikan {$request->rework_count}× ({$request->rework_cause?->label()}).", 'Pastikan kriteria acceptance disepakati sebelum dikerjakan ulang');
        }

        if ($member !== null && $request->required_skill_id !== null && $this->qualifiedCount($request) === 1) {
            $capacity = $this->capacity->capacityUntil($member, $deadline);
            $load = $this->capacity->committedHours($member) + ($request->assignee_id === $member->id ? 0.0 : $planning['hours']);
            $occupancy = $capacity > 0 ? $load / $capacity : INF;

            if ($occupancy >= (float) config('servops.risk.scarce_occupancy')) {
                $reasons[] = new Reason(
                    'SCARCE_SKILL',
                    "Hanya {$member->name} yang kompeten; bebannya ".($capacity > 0 ? round($occupancy * 100).'%' : 'melebihi').' kapasitas sampai tenggat.',
                    "Mulai lebih awal di antrean {$member->name}; rencanakan cross-training",
                );
            }
        }
    }

    /**
     * @param  list<Reason>  $reasons
     */
    private function level(CarbonImmutable $now, CarbonImmutable $deadline, ?CarbonImmutable $projected, float $planned, array $reasons): RiskLevel
    {
        if ($now->greaterThan($deadline)) {
            return RiskLevel::Breached;
        }

        $buffer = max((float) config('servops.risk.buffer_min_hours'), $planned * (float) config('servops.risk.buffer_pct'));

        $level = match (true) {
            $projected === null => RiskLevel::WillBreach,
            $projected->greaterThan($deadline) => RiskLevel::WillBreach,
            $projected->greaterThan($this->calendar->subWorkingHours($deadline, $buffer)) => RiskLevel::AtRisk,
            default => RiskLevel::OnTrack,
        };

        if ($level === RiskLevel::OnTrack) {
            foreach ($reasons as $reason) {
                if (in_array($reason->code, self::ESCALATING, true)) {
                    return RiskLevel::AtRisk;
                }
            }
        }

        return $level;
    }

    private function evaluateDelivered(ServiceRequest $request): RiskResult
    {
        $late = $request->delivered_at !== null && $request->delivered_at->greaterThan($request->deadline_at);

        return new RiskResult(
            requestId: $request->id,
            level: $late ? RiskLevel::Breached : RiskLevel::OnTrack,
            reasons: [new Reason('AWAITING_ACCEPTANCE', 'Hasil sudah diserahkan; menunggu acceptance pemohon. Belum dihitung selesai.', 'Ingatkan pemohon untuk review')],
            slackHours: null,
            latestStartAt: null,
            projectedFinishAt: $request->delivered_at ? CarbonImmutable::instance($request->delivered_at) : null,
            plannedHours: 0.0,
            projectedAssigneeId: $request->assignee_id,
        );
    }

    private function dependencyEta(ServiceRequest $request): ?CarbonImmutable
    {
        $eta = $request->openDependencies
            ->map(fn (RequestDependency $dependency) => $dependency->expected_at)
            ->filter()
            ->max();

        return $eta ? CarbonImmutable::instance($eta) : null;
    }

    private function qualifiedCount(ServiceRequest $request): int
    {
        return $this->qualifiedCounts["{$request->required_skill_id}:{$request->required_level}"] ??= TeamMember::query()
            ->active()->with('skills')->get()
            ->filter(fn (TeamMember $member) => $member->isQualifiedFor($request))
            ->count();
    }

    public function flush(): void
    {
        $this->qualifiedCounts = [];
    }
}
