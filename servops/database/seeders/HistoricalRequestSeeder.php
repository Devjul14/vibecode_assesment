<?php

namespace Database\Seeders;

use App\Enums\ActorRole;
use App\Enums\EstimateConfidence;
use App\Enums\ImpactLevel;
use App\Enums\RequesterPriority;
use App\Enums\RequestStatus;
use App\Enums\ReworkCause;
use App\Enums\TimeLogKind;
use App\Models\Department;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\TeamMember;
use App\Models\TimeLog;
use Database\Seeders\Support\Timeline;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class HistoricalRequestSeeder extends Seeder
{
    /** Pengali jam kalender kerja per jam effort (fokus ±75%, rapat, rutin). */
    private const CALENDAR_PER_EFFORT_HOUR = 1.6;

    /**
     * Profil kategori: skill, level minimum, pilihan estimasi (jam).
     *
     * @var array<string, array{skill: string, level: int, estimates: list<int>, title: string}>
     */
    private const CATEGORIES = [
        'reporting' => ['skill' => 'reporting', 'level' => 2, 'estimates' => [4, 5, 6, 8], 'title' => 'Laporan'],
        'analysis' => ['skill' => 'data_analysis', 'level' => 2, 'estimates' => [6, 8, 10, 12], 'title' => 'Analisis'],
        'incident' => ['skill' => 'troubleshooting', 'level' => 2, 'estimates' => [1, 2, 3, 4], 'title' => 'Gangguan'],
        'automation' => ['skill' => 'automation', 'level' => 3, 'estimates' => [8, 12, 16], 'title' => 'Otomatisasi'],
        'change' => ['skill' => 'process_analysis', 'level' => 2, 'estimates' => [6, 8, 12], 'title' => 'Perubahan proses'],
        'investigation' => ['skill' => 'data_analysis', 'level' => 2, 'estimates' => [4, 6, 8], 'title' => 'Investigasi'],
    ];

    private const SUBJECTS = [
        'penjualan regional', 'stok gudang', 'piutang pelanggan', 'kinerja vendor',
        'absensi karyawan', 'biaya operasional', 'retur barang', 'pengiriman harian',
    ];

    /**
     * 48 request selesai (8 per kategori) sebagai data historis durasi.
     * Item ke-8 tiap kategori adalah outlier ekstrem (3.5–4.5× estimasi) untuk menguji median/P80 vs rata-rata.
     * Sekitar 1 dari 7 request pernah dikembalikan (rework).
     */
    public function run(Timeline $timeline): void
    {
        mt_srand(20261017);

        $skills = Skill::query()->pluck('id', 'code');
        $departments = Department::query()->pluck('id')->all();
        $members = TeamMember::query()->with('skills')->get();
        $number = 1;

        foreach (self::CATEGORIES as $category => $profile) {
            $qualified = $members->filter(
                fn (TeamMember $member) => $member->skillLevel($skills[$profile['skill']]) >= $profile['level']
            )->values();

            for ($i = 0; $i < 8; $i++, $number++) {
                $this->seedRequest($timeline, $category, $profile, $i, $number, $skills[$profile['skill']], $departments, $qualified);
            }
        }
    }

    /**
     * @param  array{skill: string, level: int, estimates: list<int>, title: string}  $profile
     * @param  list<int>  $departments
     * @param  Collection<int, TeamMember>  $qualified
     */
    private function seedRequest(
        Timeline $timeline,
        string $category,
        array $profile,
        int $index,
        int $number,
        int $skillId,
        array $departments,
        Collection $qualified,
    ): void {
        $calendar = $timeline->calendar;
        $estimate = (float) $profile['estimates'][mt_rand(0, count($profile['estimates']) - 1)];
        $isOutlier = $index === 7;
        $factor = $isOutlier ? mt_rand(350, 450) / 100 : mt_rand(80, 150) / 100;
        $isRework = ! $isOutlier && $number % 7 === 0;
        $workHours = round($estimate * $factor, 1);
        $reworkHours = $isRework ? round($estimate * 0.4, 1) : 0.0;
        $assignee = $qualified[mt_rand(0, $qualified->count() - 1)];

        // Mundur dari waktu accepted agar seluruh riwayat dijamin di masa lalu.
        $queueWait = mt_rand(2, 16);
        $acceptanceWait = mt_rand(1, 8);
        $acceptedAt = $timeline->hoursAgo(mt_rand(8, 420));
        $deliveredAt = $calendar->subWorkingHours($acceptedAt, $acceptanceWait);

        if ($isRework) {
            $returnedAt = $calendar->subWorkingHours($deliveredAt, $reworkHours * self::CALENDAR_PER_EFFORT_HOUR + 1);
            $firstDeliveredAt = $calendar->subWorkingHours($returnedAt, 2);
            $reworkStartedAt = $calendar->addWorkingHours($returnedAt, 1);
        } else {
            $firstDeliveredAt = $deliveredAt;
        }

        $startedAt = $calendar->subWorkingHours($firstDeliveredAt, $workHours * self::CALENDAR_PER_EFFORT_HOUR);
        $createdAt = $calendar->subWorkingHours($startedAt, $queueWait);
        $plannedHours = 6 + $estimate * self::CALENDAR_PER_EFFORT_HOUR * 1.1 + mt_rand(2, 16);
        $deadlineAt = $calendar->endOfWorkingDay($calendar->addWorkingHours($createdAt, $plannedHours));

        $request = ServiceRequest::create([
            'code' => sprintf('SR-%03d', $number),
            'title' => $profile['title'].' '.self::SUBJECTS[mt_rand(0, count(self::SUBJECTS) - 1)],
            'category' => $category,
            'requester_department_id' => $departments[mt_rand(0, count($departments) - 1)],
            'requester_priority' => RequesterPriority::cases()[mt_rand(0, 2)],
            'impact_level' => [ImpactLevel::Low, ImpactLevel::Medium, ImpactLevel::Medium, ImpactLevel::High][mt_rand(0, 3)],
            'required_skill_id' => $skillId,
            'required_level' => $profile['level'],
            'estimate_hours' => $estimate,
            'estimate_confidence' => EstimateConfidence::Medium,
            'remaining_hours' => 0,
            'actual_hours' => $workHours + $reworkHours,
            'deadline_at' => $deadlineAt,
            'assignee_id' => $assignee->id,
            'rework_count' => $isRework ? 1 : 0,
            'rework_cause' => $isRework ? [ReworkCause::Quality, ReworkCause::UnclearScope][mt_rand(0, 1)] : null,
            'wait_hours_total' => $queueWait + $acceptanceWait + ($isRework ? 3 : 0),
            'started_at' => $startedAt,
            'delivered_at' => $deliveredAt,
            'accepted_at' => $acceptedAt,
        ]);

        $steps = [
            [RequestStatus::New, $createdAt, ActorRole::Requester],
            [RequestStatus::Triage, $calendar->addWorkingHours($createdAt, 0.25), ActorRole::TeamLead],
            [RequestStatus::Ready, $calendar->addWorkingHours($createdAt, 0.5), ActorRole::TeamLead],
            [RequestStatus::Assigned, $calendar->addWorkingHours($createdAt, 1), ActorRole::TeamLead],
            [RequestStatus::InProgress, $startedAt, ActorRole::Member, $assignee->name],
            [RequestStatus::Delivered, $firstDeliveredAt, ActorRole::Member, $assignee->name],
        ];

        if ($isRework) {
            $steps[] = [RequestStatus::Returned, $returnedAt, ActorRole::Requester, null, 'Hasil belum sesuai kebutuhan pemohon'];
            $steps[] = [RequestStatus::InProgress, $reworkStartedAt, ActorRole::Member, $assignee->name];
            $steps[] = [RequestStatus::Delivered, $deliveredAt, ActorRole::Member, $assignee->name];
        }

        $steps[] = [RequestStatus::Accepted, $acceptedAt, ActorRole::Requester];
        $timeline->recordHistory($request, $steps);

        TimeLog::create([
            'service_request_id' => $request->id,
            'team_member_id' => $assignee->id,
            'started_at' => $startedAt,
            'ended_at' => $firstDeliveredAt,
            'hours' => $workHours,
            'kind' => TimeLogKind::Work,
        ]);

        if ($isRework) {
            TimeLog::create([
                'service_request_id' => $request->id,
                'team_member_id' => $assignee->id,
                'started_at' => $reworkStartedAt,
                'ended_at' => $deliveredAt,
                'hours' => $reworkHours,
                'kind' => TimeLogKind::Rework,
            ]);
        }
    }
}
