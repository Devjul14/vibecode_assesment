<?php

namespace App\Services\Assignment;

use App\Data\AssignmentCandidate;
use App\Enums\RequestStatus;
use App\Models\RequestDependency;
use App\Models\ServiceRequest;
use App\Models\TeamMember;
use App\Services\Calendar\Clock;
use App\Services\Capacity\CapacityService;
use App\Services\Estimation\EstimateAdvisor;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Usulan penugasan (BLUEPRINT §8): filter keras kompetensi & ketersediaan, lalu ranking kecocokan.
 * Anggota di bawah syarat tidak pernah direkomendasikan otomatis — hanya sebagai opsi pairing.
 */
class AssignmentRecommender
{
    /** @var Collection<int, TeamMember>|null */
    private ?Collection $members = null;

    public function __construct(
        private Clock $clock,
        private CapacityService $capacity,
        private EstimateAdvisor $estimates,
    ) {}

    public function flush(): void
    {
        $this->members = null;
    }

    /**
     * Kandidat yang memenuhi syarat, urut dari paling cocok.
     *
     * @param  array<int, int|null>  $ranks  request_id => priority_rank (untuk antrean di depan)
     * @return Collection<int, AssignmentCandidate>
     */
    public function candidates(ServiceRequest $request, array $ranks = []): Collection
    {
        $qualified = $this->evaluateMembers($request, $ranks)
            ->filter(fn (AssignmentCandidate $candidate) => $candidate->qualified)
            ->sortByDesc('fit')
            ->values();

        // "Overqualified" hanya relevan bila ada kandidat lain dengan level yang pas.
        $hasExactMatch = $qualified->contains(fn (AssignmentCandidate $candidate) => $candidate->skillLevel === $request->required_level);

        return $hasExactMatch ? $qualified : $qualified->map(fn (AssignmentCandidate $candidate) => new AssignmentCandidate(
            ...[...get_object_vars($candidate), 'warnings' => array_values(array_filter(
                $candidate->warnings,
                fn (string $warning) => ! str_starts_with($warning, 'Overqualified'),
            ))],
        ));
    }

    /**
     * @param  array<int, int|null>  $ranks
     */
    public function best(ServiceRequest $request, array $ranks = [], ?int $excludeMemberId = null): ?AssignmentCandidate
    {
        return $this->candidates($request, $ranks)
            ->reject(fn (AssignmentCandidate $candidate) => $candidate->member->id === $excludeMemberId)
            ->first();
    }

    /**
     * Anggota di bawah syarat yang punya kapasitas — hanya untuk opsi pairing dengan mentor kompeten.
     *
     * @param  array<int, int|null>  $ranks
     * @return Collection<int, AssignmentCandidate>
     */
    public function pairingOptions(ServiceRequest $request, array $ranks = []): Collection
    {
        $planned = $this->estimates->planningHours($request)['hours'];

        return $this->evaluateMembers($request, $ranks)
            ->filter(fn (AssignmentCandidate $candidate) => ! $candidate->qualified && $candidate->skillLevel >= 1 && $candidate->availableHours >= $planned)
            ->sortByDesc('availableHours')
            ->values();
    }

    /**
     * Mentor untuk pairing: anggota kompeten dengan beban paling ringan (boleh tidak punya kapasitas penuh).
     */
    public function mentorFor(ServiceRequest $request, ?int $excludeMemberId = null): ?TeamMember
    {
        return $this->members()
            ->filter(fn (TeamMember $member) => $member->id !== $excludeMemberId && $member->isQualifiedFor($request))
            ->sortBy(fn (TeamMember $member) => $this->capacity->utilization($member))
            ->first();
    }

    /**
     * @param  array<int, int|null>  $ranks
     * @return Collection<int, AssignmentCandidate>
     */
    private function evaluateMembers(ServiceRequest $request, array $ranks): Collection
    {
        $now = $this->clock->now();
        $planned = $this->estimates->planningHours($request)['hours'];
        $notBefore = $this->dependencyEta($request);
        $deadline = CarbonImmutable::instance($request->deadline_at);
        $weights = config('servops.assignment.weights');

        return $this->members()
            ->reject(fn (TeamMember $member) => $this->capacity->absenceBetween($member, $now, $now->addMinute()) !== null)
            ->map(function (TeamMember $member) use ($request, $ranks, $planned, $notBefore, $deadline, $weights): AssignmentCandidate {
                $level = $request->required_skill_id ? $member->skillLevel($request->required_skill_id) : $request->required_level;
                $qualified = $level >= $request->required_level;
                $isCurrent = $request->assignee_id === $member->id;

                $available = $this->capacity->availableHours($member) + ($isCurrent ? (float) $request->remaining_hours : 0.0);
                $queue = $this->capacity->queueAheadHours($member, $request, $ranks);
                $projected = $this->capacity->projectFinish($member, $queue, $planned, $notBefore);
                $onTime = $projected !== null && $projected->lessThanOrEqualTo($deadline);

                $capacityFit = $planned > 0 ? min(100.0, max(0.0, $available / $planned * 100)) : 100.0;
                $deadlineFit = $onTime ? 100.0 : 0.0;
                $skillFit = match (true) {
                    ! $qualified => 0.0,
                    $level === $request->required_level => 100.0,
                    default => (float) config('servops.assignment.overqualified_score'),
                };
                $contextFit = $this->hasContext($member, $request) ? 100.0 : 0.0;

                $fit = $weights['capacity_fit'] * $capacityFit
                    + $weights['finish_before_deadline'] * $deadlineFit
                    + $weights['skill_match'] * $skillFit
                    + $weights['context'] * $contextFit;

                return new AssignmentCandidate(
                    member: $member,
                    qualified: $qualified,
                    skillLevel: $level,
                    fit: round($fit, 1),
                    availableHours: round($available, 1),
                    queueAheadHours: $queue,
                    projectedFinishAt: $projected,
                    finishesBeforeDeadline: $onTime,
                    reasons: $this->reasons($member, $request, $level, $available, $queue, $projected, $onTime, $contextFit > 0),
                    warnings: $this->warnings($member, $request, $level, $qualified),
                );
            })
            ->values();
    }

    /**
     * @return list<string>
     */
    private function reasons(TeamMember $member, ServiceRequest $request, int $level, float $available, float $queue, ?CarbonImmutable $projected, bool $onTime, bool $hasContext): array
    {
        $reasons = ["Level {$level} (syarat {$request->required_level})"];
        $reasons[] = 'Sisa kapasitas '.round($available, 1).' jam dalam '.config('servops.capacity.horizon_days').' hari kerja';

        if ($queue > 0) {
            $reasons[] = "Antrean prioritas lebih tinggi: {$queue} jam";
        }

        $reasons[] = $projected
            ? 'Perkiraan selesai '.$projected->translatedFormat('D d M H:i').($onTime ? ' — sebelum tenggat' : ' — melewati tenggat')
            : 'Tidak selesai dalam horizon proyeksi';

        if ($hasContext) {
            $reasons[] = 'Mengerjakan versi sebelumnya (konteks sudah dipahami)';
        }

        return $reasons;
    }

    /**
     * @return list<string>
     */
    private function warnings(TeamMember $member, ServiceRequest $request, int $level, bool $qualified): array
    {
        $warnings = [];

        if (! $qualified) {
            $warnings[] = 'Di bawah syarat kompetensi — hanya dengan pairing mentor';
        } elseif ($level > $request->required_level) {
            $warnings[] = 'Overqualified — kapasitas expert sebaiknya disimpan untuk pekerjaan yang membutuhkannya';
        }

        if ($this->capacity->utilization($member) > (float) config('servops.capacity.overload_threshold')) {
            $warnings[] = 'Utilisasi sudah di atas 100%';
        }

        return $warnings;
    }

    /** Konteks: assignee saat ini/sebelumnya, atau pengerja request asal rework. */
    private function hasContext(TeamMember $member, ServiceRequest $request): bool
    {
        if ($request->isRework() && $request->assignee_id === $member->id) {
            return true;
        }

        return $request->parent_request_id !== null && $request->parent?->assignee_id === $member->id;
    }

    /** ETA dependensi terbuka terakhir — pekerjaan tidak bisa dimulai sebelum itu. */
    private function dependencyEta(ServiceRequest $request): ?CarbonImmutable
    {
        if ($request->status !== RequestStatus::Blocked && ! $request->relationLoaded('openDependencies')) {
            return null;
        }

        $eta = $request->openDependencies
            ->map(fn (RequestDependency $dependency) => $dependency->expected_at)
            ->filter()
            ->max();

        return $eta ? CarbonImmutable::instance($eta) : null;
    }

    /** @return Collection<int, TeamMember> */
    private function members(): Collection
    {
        return $this->members ??= TeamMember::query()->active()->with('skills')->orderBy('name')->get();
    }
}
