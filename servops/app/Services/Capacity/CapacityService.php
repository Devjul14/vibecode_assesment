<?php

namespace App\Services\Capacity;

use App\Enums\RequestStatus;
use App\Models\CapacityEntry;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\TeamMember;
use App\Services\Calendar\Clock;
use App\Services\Calendar\WorkingCalendar;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Kapasitas efektif per anggota (BLUEPRINT §7) dan proyeksi kapan sebuah antrean pekerjaan selesai.
 *
 * effective_daily = daily_hours × focus_factor − rutin − rapat − entri kapasitas (cuti, rapat khusus, …)
 */
class CapacityService
{
    /** @var array<int, list<array{start: CarbonImmutable, end: CarbonImmutable, hours: float}>> */
    private array $timelines = [];

    /** @var array<int, Collection<int, CapacityEntry>> */
    private array $entries = [];

    /** @var array<int, Collection<int, ServiceRequest>> */
    private array $loads = [];

    public function __construct(
        private Clock $clock,
        private WorkingCalendar $calendar,
    ) {}

    public function flush(): void
    {
        $this->timelines = [];
        $this->entries = [];
        $this->loads = [];
    }

    /** Jam efektif anggota pada satu hari kerja penuh. */
    public function effectiveHoursOn(TeamMember $member, CarbonImmutable $day): float
    {
        $segments = $this->calendar->segmentsFor($day);

        if ($segments === [] || ! $member->is_active) {
            return 0.0;
        }

        $dayStart = $segments[0][0];
        $dayEnd = end($segments)[1];
        $base = $member->baseEffectiveDailyHours();
        $hours = $base;

        foreach ($this->entriesFor($member) as $entry) {
            if ($entry->ends_at->lessThanOrEqualTo($dayStart) || $entry->starts_at->greaterThanOrEqualTo($dayEnd)) {
                continue;
            }

            $overlap = $this->calendar->workingHoursBetween($entry->starts_at->max($dayStart), $entry->ends_at->min($dayEnd));

            $hours -= $entry->isAbsence()
                ? $base * $overlap / $this->calendar->hoursPerDay()
                : ($entry->hours ?? $overlap);
        }

        return round(max(0.0, $hours), 2);
    }

    /**
     * Kapasitas per hari kerja mulai dari sekarang. Hari ini dihitung proporsional terhadap sisa jam kerja.
     *
     * @return list<array{start: CarbonImmutable, end: CarbonImmutable, hours: float}>
     */
    public function timeline(TeamMember $member): array
    {
        if (isset($this->timelines[$member->id])) {
            return $this->timelines[$member->id];
        }

        $now = $this->clock->now();
        $days = (int) config('servops.risk.projection_max_days');
        $timeline = [];

        for ($day = $now->startOfDay(), $i = 0; count($timeline) < $days && $i < $days * 3; $day = $day->addDay(), $i++) {
            $segments = $this->calendar->segmentsFor($day);

            if ($segments === []) {
                continue;
            }

            $start = $segments[0][0]->max($now);
            $end = end($segments)[1];

            if ($end->lessThanOrEqualTo($start)) {
                continue;
            }

            $fraction = $this->calendar->workingHoursBetween($start, $end) / $this->calendar->hoursPerDay();
            $timeline[] = [
                'start' => $this->calendar->nextWorkingMoment($start),
                'end' => $end,
                'hours' => round($this->effectiveHoursOn($member, $day) * $fraction, 2),
            ];
        }

        return $this->timelines[$member->id] = $timeline;
    }

    /** Kapasitas efektif dari sekarang sampai batas waktu (hari terakhir dihitung proporsional). */
    public function capacityUntil(TeamMember $member, CarbonImmutable $until): float
    {
        $total = 0.0;

        foreach ($this->timeline($member) as $day) {
            if ($day['start']->greaterThanOrEqualTo($until)) {
                break;
            }

            $span = $this->calendar->workingHoursBetween($day['start'], $day['end']);
            $usable = $this->calendar->workingHoursBetween($day['start'], $day['end']->min($until));
            $total += $span > 0 ? $day['hours'] * $usable / $span : 0.0;
        }

        return round($total, 2);
    }

    /** Total kapasitas efektif dalam horizon (default 5 hari kerja). */
    public function capacityHours(TeamMember $member, ?int $horizonDays = null): float
    {
        $horizonDays ??= (int) config('servops.capacity.horizon_days');

        return round(array_sum(array_column(array_slice($this->timeline($member), 0, $horizonDays), 'hours')), 2);
    }

    /**
     * Request yang menjadi beban anggota (assigned, in_progress, blocked, returned).
     *
     * @return Collection<int, ServiceRequest>
     */
    public function loadOf(TeamMember $member): Collection
    {
        return $this->loads[$member->id] ??= ServiceRequest::query()
            ->where('assignee_id', $member->id)
            ->whereIn('status', RequestStatus::loadStatuses())
            ->get();
    }

    public function committedHours(TeamMember $member): float
    {
        return round((float) $this->loadOf($member)->sum('remaining_hours'), 2);
    }

    public function availableHours(TeamMember $member, ?int $horizonDays = null): float
    {
        return round($this->capacityHours($member, $horizonDays) - $this->committedHours($member), 2);
    }

    public function utilization(TeamMember $member, ?int $horizonDays = null): float
    {
        $capacity = $this->capacityHours($member, $horizonDays);

        if ($capacity <= 0) {
            return $this->committedHours($member) > 0 ? 9.99 : 0.0;
        }

        return round($this->committedHours($member) / $capacity, 2);
    }

    /**
     * Jam pekerjaan anggota yang antre di depan request ini menurut ranking prioritas.
     * Pekerjaan terblokir tidak dihitung karena belum bisa dikerjakan.
     *
     * @param  array<int, int|null>  $ranks  request_id => priority_rank
     */
    public function queueAheadHours(TeamMember $member, ServiceRequest $request, array $ranks): float
    {
        $myRank = $ranks[$request->id] ?? PHP_INT_MAX;

        return round((float) $this->loadOf($member)
            ->reject(fn (ServiceRequest $other) => $other->id === $request->id || $other->status === RequestStatus::Blocked)
            ->filter(fn (ServiceRequest $other) => ($ranks[$other->id] ?? PHP_INT_MAX) < $myRank)
            ->sum('remaining_hours'), 2);
    }

    /**
     * Proyeksi waktu selesai: kapasitas harian anggota dipakai untuk antrean di depan, lalu pekerjaan ini.
     * Null jika tidak selesai dalam horizon proyeksi.
     */
    public function projectFinish(TeamMember $member, float $queueAheadHours, float $workHours, ?CarbonImmutable $notBefore = null): ?CarbonImmutable
    {
        $now = $this->clock->now();
        $start = $notBefore !== null && $notBefore->greaterThan($now) ? $notBefore : $now;
        $needed = max(0.0, $queueAheadHours) + max(0.0, $workHours);

        if ($needed <= 0) {
            return $this->calendar->nextWorkingMoment($start);
        }

        foreach ($this->timeline($member) as $day) {
            if ($day['end']->lessThanOrEqualTo($start) || $day['hours'] <= 0) {
                continue;
            }

            $dayStart = $day['start']->max($start);
            $span = $this->calendar->workingHoursBetween($day['start'], $day['end']);
            $usableSpan = $this->calendar->workingHoursBetween($dayStart, $day['end']);
            $usableHours = $span > 0 ? $day['hours'] * $usableSpan / $span : 0.0;

            if ($usableHours <= 0) {
                continue;
            }

            if ($needed <= $usableHours) {
                return $this->calendar->addWorkingHours($dayStart, $usableSpan * $needed / $usableHours);
            }

            $needed -= $usableHours;
        }

        return null;
    }

    /**
     * Gambaran kapasitas seluruh tim untuk horizon.
     *
     * @return Collection<int, array{member: TeamMember, capacity: float, committed: float, available: float, utilization: float, status: string, absent_now: bool}>
     */
    public function teamSnapshot(): Collection
    {
        $now = $this->clock->now();
        $overload = (float) config('servops.capacity.overload_threshold');
        $idle = (float) config('servops.capacity.idle_threshold');

        return TeamMember::query()->active()->with('skills')->orderBy('name')->get()
            ->map(function (TeamMember $member) use ($now, $overload, $idle): array {
                $utilization = $this->utilization($member);
                $absentNow = $this->entriesFor($member)->contains(
                    fn (CapacityEntry $entry) => $entry->isAbsence() && $entry->starts_at->lessThanOrEqualTo($now) && $entry->ends_at->greaterThan($now)
                );

                return [
                    'member' => $member,
                    'capacity' => $this->capacityHours($member),
                    'committed' => $this->committedHours($member),
                    'available' => $this->availableHours($member),
                    'utilization' => $utilization,
                    'status' => match (true) {
                        $utilization > $overload => 'overload',
                        $utilization < $idle => 'idle',
                        default => 'normal',
                    },
                    'absent_now' => $absentNow,
                ];
            });
    }

    /**
     * Permintaan vs pasokan jam per skill dalam horizon — sumber deteksi bottleneck kompetensi.
     *
     * @return Collection<int, array{skill: Skill, demand: float, supply: float, ratio: float|null, qualified_members: list<string>, status: string}>
     */
    public function skillBalance(): Collection
    {
        $minLevel = (int) config('servops.capacity.qualified_min_level');
        $members = TeamMember::query()->active()->with('skills')->get();
        $open = ServiceRequest::query()
            ->whereIn('status', [...RequestStatus::loadStatuses(), RequestStatus::Triage, RequestStatus::NeedsClarification])
            ->whereNotNull('required_skill_id')
            ->get();

        return Skill::query()->orderBy('name')->get()->map(function (Skill $skill) use ($members, $open, $minLevel): array {
            $qualified = $members->filter(fn (TeamMember $member) => $member->skillLevel($skill) >= $minLevel);
            $demand = (float) $open->where('required_skill_id', $skill->id)->sum('remaining_hours');
            $supply = (float) $qualified->sum(fn (TeamMember $member) => $this->capacityHours($member));
            $ratio = $supply > 0 ? round($demand / $supply, 2) : ($demand > 0 ? null : 0.0);

            return [
                'skill' => $skill,
                'demand' => round($demand, 2),
                'supply' => round($supply, 2),
                'ratio' => $ratio,
                'qualified_members' => $qualified->pluck('name')->values()->all(),
                'status' => match (true) {
                    $ratio === null || $ratio > config('servops.capacity.bottleneck_ratio') => 'bottleneck',
                    $ratio > config('servops.capacity.bottleneck_warning_ratio') => 'warning',
                    default => 'ok',
                },
            ];
        });
    }

    /**
     * Entri kapasitas yang menutupi rentang [dari, sampai] dan merupakan ketidakhadiran.
     */
    public function absenceBetween(TeamMember $member, CarbonImmutable $from, CarbonImmutable $to): ?CapacityEntry
    {
        return $this->entriesFor($member)->first(
            fn (CapacityEntry $entry) => $entry->isAbsence() && $entry->starts_at->lessThan($to) && $entry->ends_at->greaterThan($from)
        );
    }

    /** @return Collection<int, CapacityEntry> */
    private function entriesFor(TeamMember $member): Collection
    {
        return $this->entries[$member->id] ??= $member->capacityEntries()
            ->where('ends_at', '>', $this->clock->now()->subDay())
            ->get();
    }
}
