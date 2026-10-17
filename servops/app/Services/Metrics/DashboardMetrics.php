<?php

namespace App\Services\Metrics;

use App\Data\Reason;
use App\Enums\RequestStatus;
use App\Enums\RiskLevel;
use App\Models\Recommendation;
use App\Models\RequestDependency;
use App\Models\ServiceRequest;
use App\Services\Calendar\Clock;
use App\Services\Calendar\WorkingCalendar;
use App\Services\Capacity\CapacityService;
use App\Services\Estimation\EstimateAdvisor;
use Illuminate\Support\Collection;

/**
 * Indikator dashboard (BLUEPRINT §12). Setiap indikator punya definisi eksplisit
 * yang ditampilkan di UI — bukan hanya "berapa", tetapi "mengapa" dan "apa tindakannya".
 */
class DashboardMetrics
{
    public const HISTORY_DAYS = 90;

    public function __construct(
        private Clock $clock,
        private WorkingCalendar $calendar,
        private CapacityService $capacity,
        private EstimateAdvisor $estimates,
    ) {}

    /**
     * @return array<string, array{value: string, definition: string, tone?: string}>
     */
    public function kpis(): array
    {
        $open = $this->openRequests();
        $atRisk = $open->filter(fn (ServiceRequest $request) => ($request->risk_level?->severity() ?? 0) > 0);
        $history = $this->recentAccepted();
        $onTime = $history->filter(fn (ServiceRequest $request) => $request->delivered_at && $request->delivered_at->lessThanOrEqualTo($request->deadline_at));
        $lead = $history->sum(fn (ServiceRequest $request) => max(0.0, $this->calendar->workingHoursBetween($request->created_at, $request->accepted_at)));
        $delivered = ServiceRequest::query()->whereIn('status', [RequestStatus::Delivered, RequestStatus::Accepted, RequestStatus::Returned])->get();
        $reworked = $delivered->filter(fn (ServiceRequest $request) => $request->rework_count > 0);

        return [
            'backlog' => [
                'value' => (string) $open->filter(fn (ServiceRequest $request) => $request->status->countsAsLoad())->count(),
                'definition' => 'Request berstatus siap, ditugaskan, dikerjakan, terblokir, atau rework. Sisa effort '.round($open->sum('remaining_hours'), 1).' jam.',
            ],
            'at_risk' => [
                'value' => (string) $atRisk->count(),
                'definition' => 'Proyeksi selesai mepet/melewati tenggat atau ada pemicu risiko (blokir, scope tidak jelas, skill langka).',
                'tone' => $atRisk->isNotEmpty() ? 'warning' : 'good',
            ],
            'review' => [
                'value' => (string) $open->where('needs_human_review', true)->count(),
                'definition' => 'Rekomendasi sistem perlu keputusan manusia: dampak/estimasi belum diketahui, skor berdekatan, override.',
            ],
            'awaiting' => [
                'value' => (string) $open->where('status', RequestStatus::Delivered)->count(),
                'definition' => 'Sudah diserahkan tetapi belum diterima pemohon — belum dihitung selesai.',
            ],
            'on_time' => [
                'value' => $history->isEmpty() ? '–' : round($onTime->count() / $history->count() * 100).'%',
                'definition' => "Dari {$history->count()} request yang diterima dalam ".self::HISTORY_DAYS.' hari, persentase yang diserahkan sebelum tenggat.',
            ],
            'rework_rate' => [
                'value' => $delivered->isEmpty() ? '–' : round($reworked->count() / $delivered->count() * 100).'%',
                'definition' => "Request yang pernah dikembalikan ({$reworked->count()}) ÷ semua yang pernah diserahkan ({$delivered->count()}).",
            ],
            'flow_efficiency' => [
                'value' => $lead > 0 ? round($history->sum('actual_hours') / $lead * 100).'%' : '–',
                'definition' => 'Jam pengerjaan aktif ÷ lead time (dibuat → diterima, jam kerja). Sisanya waktu menunggu.',
            ],
        ];
    }

    /**
     * Request berisiko terurut keparahan & rank, dengan alasan utama + tindakan.
     *
     * @return Collection<int, ServiceRequest>
     */
    public function needsAttention(int $limit = 6): Collection
    {
        return $this->openRequests()
            ->filter(fn (ServiceRequest $request) => ($request->risk_level?->severity() ?? 0) > 0 || $request->needs_human_review)
            ->sortBy(fn (ServiceRequest $request) => [-($request->risk_level?->severity() ?? 0), $request->priority_rank ?? PHP_INT_MAX])
            ->take($limit)
            ->values();
    }

    /**
     * Distribusi penyebab risiko pada request terbuka berisiko — menjawab "mengapa pekerjaan terlambat".
     *
     * @return list<array{code: string, label: string, count: int, requests: list<string>}>
     */
    public function riskCauses(): array
    {
        $causes = [];

        foreach ($this->openRequests() as $request) {
            if (($request->risk_level?->severity() ?? 0) === 0) {
                continue;
            }

            foreach ($request->risk_reasons ?? [] as $reason) {
                $causes[$reason['code']] ??= ['code' => $reason['code'], 'label' => Reason::label($reason['code']), 'count' => 0, 'requests' => []];
                $causes[$reason['code']]['count']++;
                $causes[$reason['code']]['requests'][] = $request->code;
            }
        }

        usort($causes, fn (array $a, array $b) => $b['count'] <=> $a['count']);

        return $causes;
    }

    /**
     * Jam menunggu per pihak untuk dependensi yang masih terbuka.
     *
     * @return list<array{party: string, requests: list<string>, waiting_hours: float, eta: string|null}>
     */
    public function blockers(): array
    {
        $now = $this->clock->now();

        return RequestDependency::query()->unresolved()
            ->with(['serviceRequest', 'dependsOn'])
            ->get()
            ->filter(fn (RequestDependency $dependency) => $dependency->serviceRequest?->status->isOpen())
            ->groupBy(fn (RequestDependency $dependency) => $dependency->external_party ?? $dependency->dependsOn?->code ?? 'Lainnya')
            ->map(fn (Collection $group, string $party) => [
                'party' => $party,
                'requests' => $group->map(fn (RequestDependency $dependency) => $dependency->serviceRequest->code)->unique()->values()->all(),
                'waiting_hours' => round($group->sum(fn (RequestDependency $dependency) => max(0.0, $this->calendar->workingHoursBetween($dependency->serviceRequest->blocked_since ?? $dependency->created_at, $now))), 1),
                'eta' => $group->pluck('expected_at')->filter()->max()?->translatedFormat('D d M H:i'),
            ])
            ->sortByDesc('waiting_hours')
            ->values()
            ->all();
    }

    /**
     * Akurasi estimasi per kategori dari data historis (median & P80 setelah outlier dibuang).
     *
     * @return list<array{category: string, n: int, outliers: int, median: float, p80: float, mean_raw: float, accuracy_median: float|null}>
     */
    public function estimationAccuracy(): array
    {
        return ServiceRequest::done()->distinct()->orderBy('category')->pluck('category')
            ->map(fn (string $category) => ($stats = $this->estimates->statsFor($category)) ? ['category' => $category, ...$stats] : null)
            ->filter()
            ->values()
            ->all();
    }

    /** @return Collection<int, Recommendation> */
    public function topRecommendations(int $limit = 5): Collection
    {
        return Recommendation::query()->open()
            ->with(['serviceRequest', 'member'])
            ->orderByDesc('severity')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * @return array<string, int> risk level => jumlah request terbuka
     */
    public function riskDistribution(): array
    {
        $open = $this->openRequests();

        return collect(RiskLevel::cases())
            ->mapWithKeys(fn (RiskLevel $level) => [$level->value => $open->where('risk_level', $level)->count()])
            ->all();
    }

    /** @return Collection<int, ServiceRequest> */
    private function openRequests(): Collection
    {
        return ServiceRequest::query()->open()->where('status', '!=', RequestStatus::New)->with('assignee')->get();
    }

    /** @return Collection<int, ServiceRequest> */
    private function recentAccepted(): Collection
    {
        return ServiceRequest::done()
            ->where('accepted_at', '>=', $this->clock->now()->subDays(self::HISTORY_DAYS))
            ->get();
    }
}
