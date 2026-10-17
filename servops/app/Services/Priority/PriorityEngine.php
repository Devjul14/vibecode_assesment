<?php

namespace App\Services\Priority;

use App\Data\PriorityResult;
use App\Data\Reason;
use App\Enums\ImpactLevel;
use App\Enums\RequestStatus;
use App\Models\PriorityOverride;
use App\Models\RequestDependency;
use App\Models\ServiceRequest;
use App\Models\TeamMember;
use App\Services\Calendar\Clock;
use App\Services\Calendar\WorkingCalendar;
use App\Services\Estimation\EstimateAdvisor;
use Illuminate\Support\Collection;

/**
 * Skor prioritas berbobot yang dapat dijelaskan (BLUEPRINT §6).
 * Prioritas pemohon hanya satu sinyal berbobot kecil; dampak & urgensi mendominasi.
 */
class PriorityEngine
{
    private const LABELS = [
        'impact' => 'Dampak bisnis',
        'urgency' => 'Urgensi waktu',
        'dependency' => 'Memblokir pekerjaan lain',
        'requester' => 'Prioritas pemohon',
        'scarcity' => 'Kelangkaan kompetensi',
        'age' => 'Lama menunggu',
    ];

    public function __construct(
        private Clock $clock,
        private WorkingCalendar $calendar,
        private EstimateAdvisor $estimates,
    ) {}

    /**
     * Hitung skor semua request lalu beri ranking. Request "delivered" diberi skor tetapi tidak diranking
     * karena tidak ada lagi pekerjaan tim yang tersisa.
     *
     * @param  Collection<int, ServiceRequest>  $requests
     * @return array<int, PriorityResult> request_id => hasil
     */
    public function evaluateAll(Collection $requests): array
    {
        $context = $this->buildContext($requests);
        $results = [];

        foreach ($requests as $request) {
            $results[$request->id] = $this->evaluate($request, $context);
        }

        $this->rank($requests, $results);

        return $results;
    }

    /**
     * @param  array{qualified: array<string, int>, dependents: array<int, int>, overrides: array<int, PriorityOverride>, members: Collection<int, TeamMember>}  $context
     */
    private function evaluate(ServiceRequest $request, array $context): PriorityResult
    {
        $weights = config('servops.priority.weights');
        $review = [];

        [$impact, $impactNote] = $this->impactFactor($request, $review);
        [$urgency, $urgencyNote] = $this->urgencyFactor($request, $review);
        [$dependency, $dependencyNote] = $this->dependencyFactor($context['dependents'][$request->id] ?? 0);
        [$scarcity, $scarcityNote] = $this->scarcityFactor($request, $context, $review);
        [$age, $ageNote] = $this->ageFactor($request);
        $requester = (float) $request->requester_priority->score();

        $raw = compact('impact', 'urgency', 'dependency', 'scarcity', 'age', 'requester');
        $notes = [
            'impact' => $impactNote,
            'urgency' => $urgencyNote,
            'dependency' => $dependencyNote,
            'requester' => "Pemohon menandai {$request->requester_priority->label()}",
            'scarcity' => $scarcityNote,
            'age' => $ageNote,
        ];

        $breakdown = [];
        $score = 0.0;

        foreach (self::LABELS as $factor => $label) {
            $contribution = round($weights[$factor] * $raw[$factor], 2);
            $score += $contribution;
            $breakdown[] = [
                'factor' => $factor,
                'label' => $label,
                'raw' => round($raw[$factor], 1),
                'weight' => $weights[$factor],
                'contribution' => $contribution,
                'note' => $notes[$factor],
            ];
        }

        $this->collectStateReviews($request, $context, $review);

        $override = $context['overrides'][$request->id] ?? null;

        return new PriorityResult(
            requestId: $request->id,
            score: round($score, 2),
            breakdown: $breakdown,
            reviewReasons: $review,
            pinnedRank: $override ? ($override->forced_rank ?? 1) : null,
        );
    }

    /**
     * @param  list<Reason>  $review
     * @return array{0: float, 1: string}
     */
    private function impactFactor(ServiceRequest $request, array &$review): array
    {
        $score = $request->impact_level->baseScore();
        $parts = ["Dampak {$request->impact_level->label()}"];

        if ($request->impact_level === ImpactLevel::Unknown) {
            $review[] = new Reason('IMPACT_UNKNOWN', 'Dampak belum diidentifikasi; dinilai setara "Sedang" sampai triage selesai.', 'Lakukan triage dampak');
        }

        if ($request->affected_departments) {
            $score += min(20, 5 * $request->affected_departments);
            $parts[] = "{$request->affected_departments} departemen terdampak";
        }

        if ($request->businessProcess) {
            $score += 5 * ($request->businessProcess->criticality - 3);
            $parts[] = "proses \"{$request->businessProcess->name}\" (kritikalitas {$request->businessProcess->criticality}/5)";
        }

        return [$this->clamp($score), implode(', ', $parts)];
    }

    /**
     * Urgensi = max(tekanan waktu, kedekatan tenggat).
     * Tekanan = jam yang dibutuhkan ÷ jam efektif tersisa; kedekatan memastikan tenggat dekat selalu terasa mendesak.
     *
     * @param  list<Reason>  $review
     * @return array{0: float, 1: string}
     */
    private function urgencyFactor(ServiceRequest $request, array &$review): array
    {
        $planning = $this->estimates->planningHours($request);
        $hoursLeft = $this->calendar->workingHoursBetween($this->clock->now(), $request->deadline_at);

        if ($planning['assumed'] && $request->status !== RequestStatus::Delivered) {
            $review[] = new Reason('ESTIMATE_UNKNOWN', "Estimasi belum diketahui. {$planning['source']}.", 'Klarifikasi scope & isi estimasi');
        }

        if ($hoursLeft <= 0) {
            return [100.0, 'Tenggat sudah terlewati'];
        }

        $effectiveLeft = $hoursLeft * (float) config('servops.priority.effective_ratio');
        $pressure = $planning['hours'] / $effectiveLeft * 100;
        $proximity = 100 * (1 - min(1, $hoursLeft / (float) config('servops.priority.proximity_horizon_hours')));

        $hours = rtrim(rtrim(number_format($planning['hours'], 1, '.', ''), '0'), '.');

        return [
            $this->clamp(max($pressure, $proximity)),
            "Butuh {$hours} jam, sisa {$hoursLeft} jam kerja sampai tenggat",
        ];
    }

    /** @return array{0: float, 1: string} */
    private function dependencyFactor(int $dependents): array
    {
        return $dependents > 0
            ? [$this->clamp(40 * $dependents), "Ditunggu oleh {$dependents} request lain"]
            : [0.0, 'Tidak memblokir request lain'];
    }

    /**
     * @param  array{qualified: array<string, int>}  $context
     * @param  list<Reason>  $review
     * @return array{0: float, 1: string}
     */
    private function scarcityFactor(ServiceRequest $request, array $context, array &$review): array
    {
        if ($request->required_skill_id === null) {
            return [0.0, 'Kompetensi belum ditentukan'];
        }

        $count = $context['qualified']["{$request->required_skill_id}:{$request->required_level}"] ?? 0;

        if ($count === 0) {
            $review[] = new Reason('NO_QUALIFIED_MEMBER', 'Tidak ada anggota aktif yang memenuhi syarat kompetensi.', 'Pairing, pelatihan, atau sumber daya eksternal');
        }

        return [
            match (true) {
                $count <= 1 => 100.0,
                $count === 2 => 60.0,
                default => 20.0,
            },
            "{$count} anggota memenuhi syarat ({$request->requiredSkill?->name} level ≥ {$request->required_level})",
        ];
    }

    /** @return array{0: float, 1: string} */
    private function ageFactor(ServiceRequest $request): array
    {
        $days = max(0, $this->calendar->workingHoursBetween($request->created_at, $this->clock->now()) / $this->calendar->hoursPerDay());

        return [$this->clamp(10 * $days), 'Menunggu '.round($days, 1).' hari kerja'];
    }

    /**
     * Pemicu review yang berasal dari kondisi request (bukan dari faktor skor).
     *
     * @param  array{overrides: array<int, PriorityOverride>, members: Collection<int, TeamMember>}  $context
     * @param  list<Reason>  $review
     */
    private function collectStateReviews(ServiceRequest $request, array $context, array &$review): void
    {
        if (isset($context['overrides'][$request->id])) {
            $override = $context['overrides'][$request->id];
            $review[] = new Reason('OVERRIDE_ACTIVE', "Prioritas di-override oleh {$override->approved_by}: {$override->reason}");
        }

        $assignee = $request->assignee_id ? $context['members']->firstWhere('id', $request->assignee_id) : null;

        if ($assignee && ! $assignee->isQualifiedFor($request)) {
            $review[] = new Reason('UNDERQUALIFIED_ASSIGNMENT', "{$assignee->name} di bawah syarat kompetensi".($request->pairing_member_id ? ' (dengan pairing)' : '').'.', 'Pastikan mentor mereview hasil');
        }

        $growth = $request->scopeGrowthPct();

        if ($growth !== null && $growth > (float) config('servops.priority.estimate_growth_pct')) {
            $review[] = new Reason('ESTIMATE_GROWTH', "Estimasi naik {$growth}% dari awal karena perubahan scope.", 'Re-baseline tenggat atau pecah scope');
        }
    }

    /**
     * Urutan: override pinned → skor tertinggi → tenggat terdekat → dampak tertinggi → paling lama.
     * Skor berdekatan pada skill yang sama ditandai CLOSE_CALL untuk diputuskan manusia.
     *
     * @param  Collection<int, ServiceRequest>  $requests
     * @param  array<int, PriorityResult>  $results
     */
    private function rank(Collection $requests, array $results): void
    {
        $rankable = $requests->reject(fn (ServiceRequest $request) => $request->status === RequestStatus::Delivered);

        $sorted = $rankable->sort(function (ServiceRequest $a, ServiceRequest $b) use ($results): int {
            $ra = $results[$a->id];
            $rb = $results[$b->id];

            return [$ra->isPinned() ? 0 : 1, $ra->pinnedRank ?? 0, -$ra->score, $a->deadline_at->getTimestamp(), -$a->impact_level->baseScore(), $a->created_at->getTimestamp()]
                <=> [$rb->isPinned() ? 0 : 1, $rb->pinnedRank ?? 0, -$rb->score, $b->deadline_at->getTimestamp(), -$b->impact_level->baseScore(), $b->created_at->getTimestamp()];
        })->values();

        $threshold = (float) config('servops.priority.close_call_points');

        foreach ($sorted as $index => $request) {
            $results[$request->id]->rank = $index + 1;
            $next = $sorted[$index + 1] ?? null;

            if ($next === null || $request->required_skill_id === null || $request->required_skill_id !== $next->required_skill_id) {
                continue;
            }

            $current = $results[$request->id];
            $other = $results[$next->id];

            if (! $current->isPinned() && ! $other->isPinned() && abs($current->score - $other->score) < $threshold) {
                $reason = new Reason('CLOSE_CALL', "Skor {$request->code} dan {$next->code} hanya selisih ".round(abs($current->score - $other->score), 1).' poin untuk kompetensi yang sama.', 'Putuskan urutan secara manual');
                $current->addReviewReason($reason);
                $other->addReviewReason($reason);
            }
        }
    }

    /**
     * @param  Collection<int, ServiceRequest>  $requests
     * @return array{qualified: array<string, int>, dependents: array<int, int>, overrides: array<int, PriorityOverride>, members: Collection<int, TeamMember>}
     */
    private function buildContext(Collection $requests): array
    {
        $members = TeamMember::query()->active()->with('skills')->get();
        $qualified = [];

        foreach ($requests->whereNotNull('required_skill_id')->unique(fn (ServiceRequest $r) => "{$r->required_skill_id}:{$r->required_level}") as $request) {
            $qualified["{$request->required_skill_id}:{$request->required_level}"] = $members
                ->filter(fn (TeamMember $member) => $member->skillLevel($request->required_skill_id) >= $request->required_level)
                ->count();
        }

        $dependents = RequestDependency::query()
            ->unresolved()
            ->whereIn('depends_on_request_id', $requests->pluck('id'))
            ->whereHas('serviceRequest', fn ($query) => $query->whereIn('status', RequestStatus::openStatuses()))
            ->selectRaw('depends_on_request_id, count(*) as total')
            ->groupBy('depends_on_request_id')
            ->pluck('total', 'depends_on_request_id')
            ->map(fn ($total): int => (int) $total)
            ->all();

        $overrides = PriorityOverride::query()
            ->activeAt($this->clock->now())
            ->whereIn('service_request_id', $requests->pluck('id'))
            ->latest('id')
            ->get()
            ->unique('service_request_id')
            ->keyBy('service_request_id')
            ->all();

        return compact('qualified', 'dependents', 'overrides', 'members');
    }

    private function clamp(float $value): float
    {
        return max(0.0, min(100.0, $value));
    }
}
