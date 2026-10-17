<?php

namespace App\Services\Recommendation;

use App\Data\PriorityResult;
use App\Data\RiskResult;
use App\Enums\ImpactLevel;
use App\Enums\RecommendationStatus;
use App\Enums\RecommendationType as T;
use App\Enums\RequestStatus;
use App\Enums\RiskLevel;
use App\Models\Recommendation;
use App\Models\ServiceRequest;
use App\Models\TeamMember;
use App\Services\Assignment\AssignmentRecommender;
use App\Services\Calendar\Clock;
use App\Services\Calendar\WorkingCalendar;
use App\Services\Capacity\CapacityService;
use Illuminate\Support\Collection;

/**
 * Menyusun rekomendasi tindakan (BLUEPRINT §11) dari hasil prioritas, risiko, dan kapasitas,
 * lalu menyinkronkannya ke tabel recommendations tanpa duplikasi (fingerprint).
 */
class RecommendationEngine
{
    public function __construct(
        private Clock $clock,
        private WorkingCalendar $calendar,
        private CapacityService $capacity,
        private AssignmentRecommender $assignments,
    ) {}

    /**
     * @param  Collection<int, ServiceRequest>  $requests
     * @param  array<int, PriorityResult>  $priorities
     * @param  array<int, RiskResult>  $risks
     * @return list<array{type: T, severity: int, title: string, rationale: string, payload: array<string, mixed>, service_request_id: int|null, team_member_id: int|null, key: string}>
     */
    public function generate(Collection $requests, array $priorities, array $risks): array
    {
        $ranks = array_map(fn (PriorityResult $result) => $result->rank, $priorities);
        $drafts = [];

        foreach ($requests as $request) {
            $risk = $risks[$request->id] ?? null;

            if ($risk === null || $request->status === RequestStatus::Delivered) {
                continue;
            }

            array_push($drafts, ...$this->forRequest($request, $risk, $ranks));
        }

        array_push($drafts, ...$this->rebalance($requests, $ranks));
        array_push($drafts, ...$this->skillCapacity());

        return $drafts;
    }

    /**
     * Simpan draf: buat yang baru, perbarui yang masih terbuka, tutup yang kondisinya sudah tidak berlaku.
     * Rekomendasi yang sudah diterima/diabaikan manusia tidak dibuka ulang.
     *
     * @param  list<array{type: T, severity: int, title: string, rationale: string, payload: array<string, mixed>, service_request_id: int|null, team_member_id: int|null, key: string}>  $drafts
     * @return array{created: int, updated: int, closed: int}
     */
    public function sync(array $drafts): array
    {
        $stats = ['created' => 0, 'updated' => 0, 'closed' => 0];
        $seen = [];

        foreach ($drafts as $draft) {
            $fingerprint = hash('sha256', $draft['type']->value.'|'.$draft['key']);
            $seen[] = $fingerprint;
            $existing = Recommendation::query()->where('fingerprint', $fingerprint)->first();

            $attributes = [
                'service_request_id' => $draft['service_request_id'],
                'team_member_id' => $draft['team_member_id'],
                'type' => $draft['type'],
                'severity' => $draft['severity'],
                'title' => $draft['title'],
                'rationale' => $draft['rationale'],
                'payload' => $draft['payload'],
            ];

            if ($existing === null) {
                Recommendation::create([...$attributes, 'status' => RecommendationStatus::Open, 'fingerprint' => $fingerprint]);
                $stats['created']++;
            } elseif ($existing->status === RecommendationStatus::Open) {
                $existing->fill($attributes)->save();
                $stats['updated']++;
            }
        }

        $stats['closed'] = Recommendation::query()
            ->open()
            ->whereNotIn('fingerprint', $seen)
            ->update([
                'status' => RecommendationStatus::Dismissed,
                'decided_by' => 'Sistem — kondisi sudah tidak berlaku',
                'decided_at' => $this->clock->now(),
            ]);

        return $stats;
    }

    /**
     * @param  array<int, int|null>  $ranks
     * @return list<array<string, mixed>>
     */
    private function forRequest(ServiceRequest $request, RiskResult $risk, array $ranks): array
    {
        $drafts = [];
        $hoursLeft = $this->calendar->workingHoursBetween($this->clock->now(), $request->deadline_at);
        $atRisk = $risk->level->severity() >= RiskLevel::AtRisk->severity();
        $willBreach = $risk->level->severity() >= RiskLevel::WillBreach->severity();

        if ($request->impact_level === ImpactLevel::Unknown) {
            $drafts[] = $this->draft(T::TriageImpact, 2, $request, null,
                "Triage dampak {$request->code}",
                'Dampak belum diidentifikasi. Prioritas pemohon "'.$request->requester_priority->label().'" belum tentu mencerminkan dampak sebenarnya — lakukan triage 1–2 jam (departemen & proses terdampak, jumlah pengguna) sebelum menjadwalkan.',
                ['action' => 'triage_impact']);
        }

        if ($request->estimate_hours === null) {
            $drafts[] = $this->draft(T::ClarifyScope, $hoursLeft < 16 ? 3 : 2, $request, null,
                "Klarifikasi scope {$request->code}",
                "Estimasi belum diketahui sehingga request tidak bisa dijadwalkan dengan aman. Sisa {$hoursLeft} jam kerja sampai tenggat. Jadwalkan sesi klarifikasi ber-timebox (2 jam) dengan pemohon, lalu isi estimasi.",
                ['action' => 'clarify_scope', 'timebox_hours' => 2]);
        }

        $reassigned = false;

        if ($request->assignee_id === null && $request->status === RequestStatus::Ready) {
            $best = $this->assignments->best($request, $ranks);

            if ($best !== null) {
                $severity = $risk->hasReason('UNASSIGNED_LOW_SLACK') || $willBreach ? 3 : ($atRisk ? 2 : 1);
                $scarce = $risk->hasReason('SCARCE_SKILL')
                    ? " Hanya {$best->member->name} yang kompeten — tugaskan sekarang sebelum kapasitasnya terpakai pekerjaan lain."
                    : '';
                $drafts[] = $this->draft(T::Reassign, $severity, $request, $best->member,
                    "Tugaskan {$request->code} ke {$best->member->name}",
                    implode('. ', $best->reasons).'.'.$scarce,
                    ['action' => 'assign', 'member_id' => $best->member->id, 'fit' => $best->fit]);
                $reassigned = true;
            }
        }

        if ($request->assignee_id !== null && ($risk->hasReason('ASSIGNEE_UNAVAILABLE') || $risk->hasReason('QUEUE_OVERLOAD'))) {
            $best = $this->assignments->best($request, $ranks, excludeMemberId: $request->assignee_id);

            if ($best !== null && $best->finishesBeforeDeadline) {
                $why = $risk->hasReason('ASSIGNEE_UNAVAILABLE') ? "{$request->assignee->name} tidak tersedia" : "antrean {$request->assignee->name} terlalu panjang";
                $drafts[] = $this->draft(T::Reassign, 3, $request, $best->member,
                    "Pindahkan {$request->code} dari {$request->assignee->name} ke {$best->member->name}",
                    ucfirst($why).'. '.implode('. ', $best->reasons).'.',
                    ['action' => 'reassign', 'member_id' => $best->member->id, 'from_member_id' => $request->assignee_id]);
                $reassigned = true;
            }
        }

        $needsHelp = $risk->hasReason('NO_QUALIFIED_CAPACITY') || ($willBreach && ! $reassigned);

        if ($needsHelp && $request->status->isOpen()) {
            $pairing = $this->pairingDraft($request, $ranks);

            if ($pairing !== null) {
                $drafts[] = $pairing;
            } else {
                $drafts[] = $this->draft(T::NegotiateDeadline, 3, $request, null,
                    "Negosiasi tenggat {$request->code}",
                    'Perkiraan selesai melewati tenggat dan tidak ada anggota kompeten yang bisa mengambil alih tepat waktu. Sepakati tenggat baru atau prioritas ulang dengan pemohon.',
                    ['action' => 'negotiate_deadline', 'projected_finish_at' => $risk->projectedFinishAt?->toIso8601String()]);

                if ($risk->plannedHours >= 8) {
                    $drafts[] = $this->draft(T::SplitScope, 2, $request, null,
                        "Pecah scope {$request->code}",
                        "Pekerjaan {$risk->plannedHours} jam dapat dipecah agar bagian paling bernilai selesai sebelum tenggat.",
                        ['action' => 'split_scope']);
                }
            }
        }

        if ($request->openDependencies->isNotEmpty() && ($atRisk || $risk->hasReason('EXTERNAL_ETA_LATE') || $risk->hasReason('BLOCKED_NEAR_LATEST_START'))) {
            $parties = $request->openDependencies->map(fn ($dependency) => $dependency->external_party ?? $dependency->dependsOn?->code)->filter()->unique()->implode(', ');
            $drafts[] = $this->draft(T::EscalateDependency, $willBreach ? 3 : 2, $request, null,
                "Eskalasi dependensi {$request->code} ke {$parties}",
                "{$request->code} menunggu {$parties}. Batas mulai paling lambat ".($risk->latestStartAt?->translatedFormat('D d M H:i') ?? '-').'. Minta kepastian ETA sekarang, jangan tunggu sampai tenggat dekat.',
                ['action' => 'escalate_dependency', 'parties' => $parties]);
        }

        if ($risk->hasReason('SCARCE_SKILL') && $request->assignee_id !== null && $request->status !== RequestStatus::InProgress) {
            $member = $request->assignee;
            $drafts[] = $this->draft(T::StartEarly, 2, $request, $member,
                "Mulai {$request->code} lebih awal ({$member?->name})",
                "Hanya {$member?->name} yang memenuhi syarat {$request->requiredSkill?->name} level {$request->required_level}. Perkiraan selesai ".($risk->projectedFinishAt?->translatedFormat('D d M H:i') ?? '-').' — margin ke tenggat tipis. Naikkan di antrean sebelum kapasitasnya terpakai pekerjaan lain.',
                ['action' => 'start_early', 'member_id' => $member?->id]);
        }

        return $drafts;
    }

    /**
     * @param  array<int, int|null>  $ranks
     * @return array<string, mixed>|null
     */
    private function pairingDraft(ServiceRequest $request, array $ranks): ?array
    {
        $option = $this->assignments->pairingOptions($request, $ranks)->first();
        $mentor = $option ? $this->assignments->mentorFor($request, $option->member->id) : null;

        if ($option === null || $mentor === null) {
            return null;
        }

        return $this->draft(T::Pairing, 2, $request, $option->member,
            "Pairing {$request->code}: {$option->member->name} dengan mentor {$mentor->name}",
            "{$option->member->name} punya kapasitas {$option->availableHours} jam tetapi level {$option->skillLevel} (syarat {$request->required_level}). Anggota kompeten tidak bisa menyelesaikan tepat waktu. Pairing dengan {$mentor->name} sebagai reviewer — keputusan manusia, bukan penugasan otomatis.",
            ['action' => 'pairing', 'member_id' => $option->member->id, 'mentor_id' => $mentor->id]);
    }

    /**
     * Anggota overload dengan pekerjaan belum dimulai yang bisa diambil anggota idle yang kompeten.
     *
     * @param  Collection<int, ServiceRequest>  $requests
     * @param  array<int, int|null>  $ranks
     * @return list<array<string, mixed>>
     */
    private function rebalance(Collection $requests, array $ranks): array
    {
        $snapshot = $this->capacity->teamSnapshot();
        $overloaded = $snapshot->filter(fn (array $row) => $row['utilization'] > (float) config('servops.recommendation.rebalance_overload'));
        $idle = $snapshot->filter(fn (array $row) => ! $row['absent_now'] && $row['utilization'] < (float) config('servops.recommendation.rebalance_idle'));
        $drafts = [];

        foreach ($overloaded as $row) {
            $movable = $requests
                ->filter(fn (ServiceRequest $request) => $request->assignee_id === $row['member']->id && $request->status === RequestStatus::Assigned)
                ->sortByDesc(fn (ServiceRequest $request) => $ranks[$request->id] ?? PHP_INT_MAX);

            foreach ($movable as $request) {
                $target = $idle->first(fn (array $candidate) => $candidate['member']->isQualifiedFor($request) && $candidate['available'] >= (float) $request->remaining_hours);

                if ($target !== null) {
                    $drafts[] = $this->draft(T::Rebalance, 2, $request, $target['member'],
                        "Seimbangkan beban: {$request->code} dari {$row['member']->name} ke {$target['member']->name}",
                        "{$row['member']->name} terisi ".round($row['utilization'] * 100)."%, {$target['member']->name} ".round($target['utilization'] * 100)."% dan kompeten. {$request->code} belum dimulai sehingga murah dipindahkan.",
                        ['action' => 'reassign', 'member_id' => $target['member']->id, 'from_member_id' => $row['member']->id]);

                    break;
                }
            }
        }

        return $drafts;
    }

    /**
     * Bottleneck kompetensi: permintaan melebihi pasokan, atau hanya satu orang yang kompeten.
     *
     * @return list<array<string, mixed>>
     */
    private function skillCapacity(): array
    {
        $drafts = [];
        $members = TeamMember::query()->active()->with('skills')->get();

        foreach ($this->capacity->skillBalance() as $row) {
            $skill = $row['skill'];
            $learners = $members->filter(fn (TeamMember $member) => $member->skillLevel($skill) === 1)->pluck('name');

            if ($row['status'] === 'bottleneck' && $row['demand'] > 0) {
                $type = $learners->isNotEmpty() ? T::CrossTrain : T::AddCapacity;
                $drafts[] = [
                    'type' => $type,
                    'severity' => 3,
                    'title' => "Bottleneck {$skill->name}: permintaan {$row['demand']} jam vs pasokan {$row['supply']} jam",
                    'rationale' => 'Kebutuhan melebihi kapasitas anggota kompeten ('.(implode(', ', $row['qualified_members']) ?: 'tidak ada').') dalam '.config('servops.capacity.horizon_days').' hari kerja. '
                        .($learners->isNotEmpty() ? 'Percepat pelatihan '.$learners->implode(', ').'.' : 'Pertimbangkan tambahan kapasitas atau tunda pekerjaan berprioritas rendah.'),
                    'payload' => ['skill_id' => $skill->id, 'demand' => $row['demand'], 'supply' => $row['supply']],
                    'service_request_id' => null,
                    'team_member_id' => null,
                    'key' => "skill:{$skill->id}",
                ];
            } elseif (count($row['qualified_members']) === 1 && $row['demand'] > 0) {
                $drafts[] = [
                    'type' => T::CrossTrain,
                    'severity' => 1,
                    'title' => "Single point of failure: {$skill->name} hanya {$row['qualified_members'][0]}",
                    'rationale' => "Semua pekerjaan {$skill->name} bergantung pada satu orang. Jika {$row['qualified_members'][0]} tidak tersedia, pekerjaan berhenti. "
                        .($learners->isNotEmpty() ? 'Kandidat cross-training: '.$learners->implode(', ').'.' : 'Belum ada anggota dengan dasar skill ini.'),
                    'payload' => ['skill_id' => $skill->id],
                    'service_request_id' => null,
                    'team_member_id' => null,
                    'key' => "spof:{$skill->id}",
                ];
            }
        }

        return $drafts;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function draft(T $type, int $severity, ServiceRequest $request, ?TeamMember $member, string $title, string $rationale, array $payload): array
    {
        return [
            'type' => $type,
            'severity' => $severity,
            'title' => $title,
            'rationale' => $rationale,
            'payload' => $payload,
            'service_request_id' => $request->id,
            'team_member_id' => $member?->id,
            'key' => "request:{$request->id}:member:".($member?->id ?? '-'),
        ];
    }
}
