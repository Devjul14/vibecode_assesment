<?php

namespace App\Services\Recommendation;

use App\Enums\RiskLevel;
use App\Models\ServiceRequest;
use App\Services\Metrics\MetricsRecomputer;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * "What-if" tanpa efek samping: jalankan perubahan + seluruh engine di dalam transaksi,
 * baca hasilnya, lalu rollback. Manajemen melihat harga keputusan sebelum menyimpannya.
 */
class ImpactPreviewService
{
    public function __construct(private MetricsRecomputer $recomputer) {}

    /**
     * @param  Closure(): mixed  $mutation  perubahan yang ingin dicoba
     * @return list<array{code: string, title: string, rank_before: int|null, rank_after: int|null, risk_before: RiskLevel|null, risk_after: RiskLevel|null, finish_before: string|null, finish_after: string|null, worse: bool}>
     */
    public function preview(Closure $mutation): array
    {
        $before = $this->snapshot();

        DB::beginTransaction();

        try {
            $mutation();
            $this->recomputer->run();
            $after = $this->snapshot();
        } finally {
            DB::rollBack();
            $this->recomputer->flushCaches();
        }

        $rows = [];

        foreach ($after as $code => $state) {
            $old = $before[$code] ?? null;

            if ($old !== null && $old['rank'] === $state['rank'] && $old['risk'] === $state['risk'] && $old['finish'] === $state['finish']) {
                continue;
            }

            $rows[] = [
                'code' => $code,
                'title' => $state['title'],
                'rank_before' => $old['rank'] ?? null,
                'rank_after' => $state['rank'],
                'risk_before' => $old['risk'] ?? null,
                'risk_after' => $state['risk'],
                'finish_before' => $old['finish'] ?? null,
                'finish_after' => $state['finish'],
                'worse' => ($state['risk']?->severity() ?? 0) > ($old['risk']?->severity() ?? 0),
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['worse'], $a['rank_after'] ?? PHP_INT_MAX] <=> [$a['worse'], $b['rank_after'] ?? PHP_INT_MAX]);

        return $rows;
    }

    /**
     * @return array<string, array{title: string, rank: int|null, risk: RiskLevel|null, finish: string|null}>
     */
    private function snapshot(): array
    {
        return ServiceRequest::query()
            ->open()
            ->get(['code', 'title', 'priority_rank', 'risk_level', 'projected_finish_at'])
            ->mapWithKeys(fn (ServiceRequest $request) => [$request->code => [
                'title' => $request->title,
                'rank' => $request->priority_rank,
                'risk' => $request->risk_level,
                'finish' => $request->projected_finish_at?->format('Y-m-d H:i'),
            ]])
            ->all();
    }
}
