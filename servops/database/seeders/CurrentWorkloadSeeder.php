<?php

namespace Database\Seeders;

use App\Enums\ActorRole;
use App\Enums\CapacityEntryType;
use App\Enums\DependencyType;
use App\Enums\EstimateConfidence;
use App\Enums\ImpactLevel;
use App\Enums\RequesterPriority;
use App\Enums\RequestStatus as S;
use App\Enums\ReworkCause;
use App\Enums\TimeLogKind;
use App\Models\BusinessProcess;
use App\Models\CapacityEntry;
use App\Models\Department;
use App\Models\EstimateRevision;
use App\Models\RequestDependency;
use App\Models\ServiceRequest;
use App\Models\Skill;
use App\Models\TeamMember;
use App\Models\TimeLog;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\Timeline;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

class CurrentWorkloadSeeder extends Seeder
{
    /** @var Collection<string, int> */
    private Collection $skills;

    /** @var Collection<string, int> */
    private Collection $departments;

    /** @var Collection<string, int> */
    private Collection $processes;

    /** @var Collection<string, TeamMember> */
    private Collection $members;

    private Timeline $timeline;

    /**
     * Kondisi saat ini: pekerjaan berjalan (WIP) + 7 request dari soal (SR-101..SR-107).
     */
    public function run(Timeline $timeline): void
    {
        $this->timeline = $timeline;
        $this->skills = Skill::query()->pluck('id', 'code');
        $this->departments = Department::query()->pluck('id', 'code');
        $this->processes = BusinessProcess::query()->pluck('id', 'name');
        $this->members = TeamMember::query()->get()->keyBy('name');

        $this->seedWorkInProgress();
        $this->seedAssessmentRequests();
        $this->seedCapacityEntries();
    }

    /**
     * Pekerjaan yang sedang berjalan — memakan kapasitas sebelum request baru masuk.
     */
    private function seedWorkInProgress(): void
    {
        $t = $this->timeline;

        $wip = [
            ['SR-095', 'Dashboard KPI penjualan', 'analysis', 'SLS', 'data_analysis', 2, 'Andi', 8, 4, 3, 'Pengelolaan retur pelanggan'],
            ['SR-096', 'Pemulihan akses aplikasi gudang', 'incident', 'OPS', 'troubleshooting', 2, 'Budi', 3, 2, 1, 'Order-to-cash'],
            ['SR-097', 'Integrasi API data vendor', 'automation', 'IT', 'automation', 3, 'Citra', 16, 8, 4, 'Laporan operasional rutin'],
            ['SR-098', 'Penyusunan SOP retur barang', 'change', 'SLS', 'process_analysis', 2, 'Eko', 6, 3, 2, 'Pengelolaan retur pelanggan'],
        ];

        foreach ($wip as [$code, $title, $category, $dept, $skill, $level, $member, $estimate, $remaining, $deadlineDays, $process]) {
            $assignee = $this->members[$member];
            $worked = $estimate - $remaining;
            $startedAt = $t->hoursAgo($worked * 1.6);
            $createdAt = $t->calendar->subWorkingHours($startedAt, 4);

            $request = $this->createRequest([
                'code' => $code,
                'title' => $title,
                'category' => $category,
                'requester_department_id' => $this->departments[$dept],
                'business_process_id' => $this->processes[$process],
                'requester_priority' => RequesterPriority::Medium,
                'impact_level' => ImpactLevel::Medium,
                'required_skill_id' => $this->skills[$skill],
                'required_level' => $level,
                'estimate_hours' => $estimate,
                'remaining_hours' => $remaining,
                'actual_hours' => $worked,
                'deadline_at' => $t->endOfDay($deadlineDays),
                'assignee_id' => $assignee->id,
                'started_at' => $startedAt,
            ], $createdAt);

            $t->recordHistory($request, [
                [S::New, $createdAt, ActorRole::Requester],
                [S::Triage, $t->calendar->addWorkingHours($createdAt, 0.5), ActorRole::TeamLead],
                [S::Ready, $t->calendar->addWorkingHours($createdAt, 1), ActorRole::TeamLead],
                [S::Assigned, $t->calendar->addWorkingHours($createdAt, 1.5), ActorRole::TeamLead],
                [S::InProgress, $startedAt, ActorRole::Member, $assignee->name],
            ]);

            $this->logWork($request, $assignee, $startedAt, $worked);
        }
    }

    /**
     * SR-101..SR-107 sesuai data awal soal, beserta konteks yang membuatnya "menjebak".
     */
    private function seedAssessmentRequests(): void
    {
        $t = $this->timeline;
        $cal = $t->calendar;

        // SR-101 — prioritas pemohon tinggi, belum ditugaskan, slack masih cukup.
        $createdAt = $t->hoursAgo(6);
        $sr101 = $this->createRequest([
            'code' => 'SR-101',
            'title' => 'Laporan analisis bulanan',
            'description' => 'Laporan analisis kinerja bulanan untuk rapat manajemen.',
            'category' => 'reporting',
            'requester_department_id' => $this->departments['MGT'],
            'business_process_id' => $this->processes['Pelaporan manajemen bulanan'],
            'requester_priority' => RequesterPriority::High,
            'impact_level' => ImpactLevel::Medium,
            'affected_departments' => 1,
            'required_skill_id' => $this->skills['reporting'],
            'estimate_hours' => 6,
            'remaining_hours' => 6,
            'deadline_at' => $t->endOfDay(2),
        ], $createdAt);
        $t->recordHistory($sr101, [
            [S::New, $createdAt, ActorRole::Requester],
            [S::Triage, $cal->addWorkingHours($createdAt, 1), ActorRole::TeamLead],
            [S::Ready, $cal->addWorkingHours($createdAt, 2), ActorRole::TeamLead],
        ]);

        // SR-102 — label pemohon "sedang", padahal gangguan berdampak ke 3 departemen & tenggat hari ini.
        $createdAt = $t->hoursAgo(0.5);
        $sr102 = $this->createRequest([
            'code' => 'SR-102',
            'title' => 'Gangguan proses operasional',
            'description' => 'Proses posting order gagal untuk sebagian pengguna sejak pagi.',
            'category' => 'incident',
            'requester_department_id' => $this->departments['OPS'],
            'business_process_id' => $this->processes['Order-to-cash'],
            'requester_priority' => RequesterPriority::Medium,
            'impact_level' => ImpactLevel::High,
            'affected_users' => 40,
            'affected_departments' => 3,
            'required_skill_id' => $this->skills['troubleshooting'],
            'estimate_hours' => 2,
            'remaining_hours' => 2,
            'deadline_at' => $t->endOfDay(0),
        ], $createdAt);
        $t->recordHistory($sr102, [
            [S::New, $createdAt, ActorRole::Requester],
            [S::Triage, $cal->addWorkingHours($createdAt, 0.15), ActorRole::TeamLead],
            [S::Ready, $cal->addWorkingHours($createdAt, 0.3), ActorRole::TeamLead],
        ]);

        // SR-103 — "rendah", tetapi 12 jam & hanya Citra yang memenuhi automation level 3.
        $createdAt = $t->hoursAgo(16);
        $sr103 = $this->createRequest([
            'code' => 'SR-103',
            'title' => 'Otomatisasi laporan rutin',
            'description' => 'Otomatisasi penarikan dan distribusi laporan operasional harian.',
            'category' => 'automation',
            'requester_department_id' => $this->departments['OPS'],
            'business_process_id' => $this->processes['Laporan operasional rutin'],
            'requester_priority' => RequesterPriority::Low,
            'impact_level' => ImpactLevel::Medium,
            'affected_departments' => 2,
            'required_skill_id' => $this->skills['automation'],
            'required_level' => 3,
            'estimate_hours' => 12,
            'remaining_hours' => 12,
            'deadline_at' => $t->endOfDay(5),
        ], $createdAt);
        $t->recordHistory($sr103, [
            [S::New, $createdAt, ActorRole::Requester],
            [S::Triage, $cal->addWorkingHours($createdAt, 1), ActorRole::TeamLead],
            [S::Ready, $cal->addWorkingHours($createdAt, 2), ActorRole::TeamLead],
        ]);

        // SR-104 — rework: percobaan pertama 5 jam dikembalikan, butuh 4 jam lagi (total estimasi 9 jam).
        $dewi = $this->members['Dewi'];
        $createdAt = $t->hoursAgo(30);
        $startedAt = $cal->addWorkingHours($createdAt, 4);
        $deliveredAt = $t->hoursAgo(8);
        $returnedAt = $t->hoursAgo(3);
        $sr104 = $this->createRequest([
            'code' => 'SR-104',
            'title' => 'Perbaikan hasil pekerjaan — Laporan rekap penjualan mingguan',
            'description' => 'Hasil sebelumnya dikembalikan: angka region Timur tidak memakai definisi yang diminta pemohon.',
            'category' => 'reporting',
            'requester_department_id' => $this->departments['SLS'],
            'business_process_id' => $this->processes['Pengelolaan retur pelanggan'],
            'requester_priority' => RequesterPriority::High,
            'impact_level' => ImpactLevel::Medium,
            'affected_departments' => 1,
            'required_skill_id' => $this->skills['reporting'],
            'estimate_hours' => 9,
            'remaining_hours' => 4,
            'actual_hours' => 5,
            'deadline_at' => $t->endOfDay(1),
            'assignee_id' => $dewi->id,
            'rework_count' => 1,
            'rework_cause' => ReworkCause::UnclearScope,
            'started_at' => $startedAt,
            'delivered_at' => $deliveredAt,
        ], $createdAt, initialEstimate: 5);
        $t->recordHistory($sr104, [
            [S::New, $createdAt, ActorRole::Requester],
            [S::Triage, $cal->addWorkingHours($createdAt, 1), ActorRole::TeamLead],
            [S::Ready, $cal->addWorkingHours($createdAt, 2), ActorRole::TeamLead],
            [S::Assigned, $cal->addWorkingHours($createdAt, 3), ActorRole::TeamLead],
            [S::InProgress, $startedAt, ActorRole::Member, $dewi->name],
            [S::Delivered, $deliveredAt, ActorRole::Member, $dewi->name],
            [S::Returned, $returnedAt, ActorRole::Requester, 'Tim Sales', 'Angka region Timur tidak memakai definisi yang diminta.'],
        ]);
        $this->logWork($sr104, $dewi, $startedAt, 5);
        EstimateRevision::create([
            'service_request_id' => $sr104->id,
            'old_hours' => 5,
            'new_hours' => 9,
            'delta_pct' => 80,
            'kind' => 'rework',
            'scope_change' => false,
            'reason' => 'Rework 4 jam setelah hasil dikembalikan pemohon.',
            'actor_role' => ActorRole::Member,
            'actor_name' => $dewi->name,
            'created_at' => $returnedAt,
        ]);

        // SR-105 — terblokir data dari Tim Finance; ETA mepet dengan latest start.
        $andi = $this->members['Andi'];
        $createdAt = $t->hoursAgo(20);
        $startedAt = $t->hoursAgo(6);
        $blockedAt = $t->hoursAgo(5.5);
        $sr105 = $this->createRequest([
            'code' => 'SR-105',
            'title' => 'Penyusunan laporan manajemen',
            'description' => 'Laporan manajemen kuartalan; butuh data penjualan Q3 final dari Finance.',
            'category' => 'reporting',
            'requester_department_id' => $this->departments['MGT'],
            'business_process_id' => $this->processes['Pelaporan manajemen bulanan'],
            'requester_priority' => RequesterPriority::High,
            'impact_level' => ImpactLevel::High,
            'affected_departments' => 3,
            'required_skill_id' => $this->skills['reporting'],
            'estimate_hours' => 5,
            'remaining_hours' => 5,
            'deadline_at' => $t->endOfDay(2),
            'assignee_id' => $andi->id,
            'started_at' => $startedAt,
            'blocked_since' => $blockedAt,
        ], $createdAt);
        $t->recordHistory($sr105, [
            [S::New, $createdAt, ActorRole::Requester],
            [S::Triage, $cal->addWorkingHours($createdAt, 1), ActorRole::TeamLead],
            [S::Ready, $cal->addWorkingHours($createdAt, 2), ActorRole::TeamLead],
            [S::Assigned, $cal->addWorkingHours($createdAt, 3), ActorRole::TeamLead],
            [S::InProgress, $startedAt, ActorRole::Member, $andi->name],
            [S::Blocked, $blockedAt, ActorRole::Member, $andi->name, 'Menunggu data penjualan Q3 final dari Tim Finance.'],
        ]);
        RequestDependency::create([
            'service_request_id' => $sr105->id,
            'type' => DependencyType::External,
            'external_party' => 'Tim Finance',
            'description' => 'Data penjualan Q3 final',
            'expected_at' => $cal->endOfWorkingDay($t->now, 1)->subHours(2),
        ]);

        // SR-106 — kebutuhan belum jelas, estimasi belum diketahui: tidak boleh dijadwalkan.
        $createdAt = $t->hoursAgo(10);
        $sr106 = $this->createRequest([
            'code' => 'SR-106',
            'title' => 'Permintaan perubahan proses',
            'description' => 'Perubahan alur persetujuan diskon; detail kebutuhan belum didefinisikan.',
            'category' => 'change',
            'requester_department_id' => $this->departments['SLS'],
            'business_process_id' => $this->processes['Pengelolaan retur pelanggan'],
            'requester_priority' => RequesterPriority::Medium,
            'impact_level' => ImpactLevel::Medium,
            'required_skill_id' => $this->skills['process_analysis'],
            'estimate_hours' => null,
            'estimate_confidence' => EstimateConfidence::Low,
            'remaining_hours' => null,
            'deadline_at' => $t->endOfDay(3),
        ], $createdAt);
        $t->recordHistory($sr106, [
            [S::New, $createdAt, ActorRole::Requester],
            [S::Triage, $cal->addWorkingHours($createdAt, 1), ActorRole::TeamLead],
            [S::NeedsClarification, $cal->addWorkingHours($createdAt, 2), ActorRole::TeamLead, null, 'Kebutuhan belum terdefinisi; perlu workshop dengan Sales.'],
        ]);

        // SR-107 — "rendah", tetapi dampak belum diidentifikasi: wajib triage, bukan diasumsikan kecil.
        $createdAt = $t->hoursAgo(4);
        $sr107 = $this->createRequest([
            'code' => 'SR-107',
            'title' => 'Investigasi ketidaksesuaian data',
            'description' => 'Selisih total penjualan antara sistem order dan laporan keuangan.',
            'category' => 'investigation',
            'requester_department_id' => $this->departments['FIN'],
            'business_process_id' => $this->processes['Rekonsiliasi data penjualan'],
            'requester_priority' => RequesterPriority::Low,
            'impact_level' => ImpactLevel::Unknown,
            'required_skill_id' => $this->skills['data_analysis'],
            'estimate_hours' => 8,
            'estimate_confidence' => EstimateConfidence::Low,
            'remaining_hours' => 8,
            'deadline_at' => $t->endOfDay(4),
        ], $createdAt);
        $t->recordHistory($sr107, [
            [S::New, $createdAt, ActorRole::Requester],
            [S::Triage, $cal->addWorkingHours($createdAt, 1), ActorRole::TeamLead],
        ]);
    }

    /**
     * Eko cuti satu hari penuh pada hari kerja ke-4 — memengaruhi rencana kapasitas.
     */
    private function seedCapacityEntries(): void
    {
        $day = $this->timeline->endOfDay(4)->startOfDay();

        CapacityEntry::create([
            'team_member_id' => $this->members['Eko']->id,
            'type' => CapacityEntryType::Leave,
            'starts_at' => $day,
            'ends_at' => $day->endOfDay(),
            'note' => 'Cuti tahunan',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function createRequest(array $attributes, CarbonImmutable $createdAt, ?float $initialEstimate = null): ServiceRequest
    {
        $request = ServiceRequest::create([
            'required_level' => 2,
            'estimate_confidence' => EstimateConfidence::Medium,
            ...$attributes,
            'status' => S::New,
        ]);

        $estimate = $initialEstimate ?? $attributes['estimate_hours'];

        if ($estimate !== null) {
            EstimateRevision::create([
                'service_request_id' => $request->id,
                'old_hours' => null,
                'new_hours' => $estimate,
                'kind' => 'initial',
                'reason' => 'Estimasi awal saat triage.',
                'actor_role' => ActorRole::TeamLead,
                'created_at' => $this->timeline->calendar->addWorkingHours($createdAt, 0.5),
            ]);
        }

        return $request;
    }

    private function logWork(ServiceRequest $request, TeamMember $member, CarbonImmutable $startedAt, float $hours): void
    {
        TimeLog::create([
            'service_request_id' => $request->id,
            'team_member_id' => $member->id,
            'started_at' => $startedAt,
            'ended_at' => $this->timeline->calendar->addWorkingHours($startedAt, $hours * 1.6),
            'hours' => $hours,
            'kind' => TimeLogKind::Work,
        ]);
    }
}
