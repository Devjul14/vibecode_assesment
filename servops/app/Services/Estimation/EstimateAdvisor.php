<?php

namespace App\Services\Estimation;

use App\Models\ServiceRequest;

/**
 * Estimasi berbasis data historis yang tahan outlier: median & P80 setelah membuang outlier (IQR),
 * bukan rata-rata yang mudah terdistorsi durasi ekstrem.
 */
class EstimateAdvisor
{
    /** @var array<string, array{n: int, outliers: int, median: float, p80: float, mean_raw: float, accuracy_median: float|null}|null> */
    private array $cache = [];

    /**
     * Statistik durasi aktual kategori.
     *
     * @return array{n: int, outliers: int, median: float, p80: float, mean_raw: float, accuracy_median: float|null}|null
     */
    public function statsFor(string $category): ?array
    {
        if (array_key_exists($category, $this->cache)) {
            return $this->cache[$category];
        }

        $rows = ServiceRequest::done()
            ->where('category', $category)
            ->where('actual_hours', '>', 0)
            ->get(['actual_hours', 'estimate_hours']);

        $actuals = $rows->pluck('actual_hours')->map(fn ($hours): float => (float) $hours)->all();
        $ratios = $rows->filter(fn (ServiceRequest $row) => $row->estimate_hours > 0)
            ->map(fn (ServiceRequest $row): float => $row->actual_hours / $row->estimate_hours)
            ->values()->all();

        $summary = self::summarize($actuals, config('servops.estimation.iqr_multiplier'), config('servops.estimation.planning_percentile'));
        $minSamples = config('servops.estimation.min_samples');

        if ($summary === null || $summary['n'] < $minSamples) {
            return $this->cache[$category] = null;
        }

        $accuracy = self::summarize($ratios, config('servops.estimation.iqr_multiplier'), 50);

        return $this->cache[$category] = [
            ...$summary,
            'accuracy_median' => $accuracy ? round($accuracy['median'], 2) : null,
        ];
    }

    /**
     * Jam yang dipakai untuk perencanaan: sisa effort jika diketahui, selain itu P80 historis kategori.
     *
     * @return array{hours: float, assumed: bool, source: string}
     */
    public function planningHours(ServiceRequest $request): array
    {
        if ($request->remaining_hours !== null) {
            return ['hours' => (float) $request->remaining_hours, 'assumed' => false, 'source' => 'Sisa effort tercatat'];
        }

        if ($request->estimate_hours !== null) {
            return ['hours' => (float) $request->estimate_hours, 'assumed' => false, 'source' => 'Estimasi tercatat'];
        }

        $stats = $this->statsFor($request->category);

        if ($stats !== null) {
            return [
                'hours' => $stats['p80'],
                'assumed' => true,
                'source' => "Asumsi P80 historis kategori {$request->category} ({$stats['p80']} jam, n={$stats['n']})",
            ];
        }

        $fallback = (float) config('servops.estimation.fallback_hours');

        return ['hours' => $fallback, 'assumed' => true, 'source' => "Asumsi default {$fallback} jam (data historis kurang)"];
    }

    public function flush(): void
    {
        $this->cache = [];
    }

    /**
     * Ringkasan statistik setelah membuang outlier di luar [Q1 − k·IQR, Q3 + k·IQR].
     *
     * @param  list<float>  $values
     * @return array{n: int, outliers: int, median: float, p80: float, mean_raw: float}|null
     */
    public static function summarize(array $values, float $iqrMultiplier = 1.5, int $planningPercentile = 80): ?array
    {
        if ($values === []) {
            return null;
        }

        sort($values);
        $q1 = self::percentile($values, 25);
        $q3 = self::percentile($values, 75);
        $iqr = $q3 - $q1;
        $low = $q1 - $iqrMultiplier * $iqr;
        $high = $q3 + $iqrMultiplier * $iqr;

        $kept = array_values(array_filter($values, fn (float $value): bool => $value >= $low && $value <= $high));

        return [
            'n' => count($kept),
            'outliers' => count($values) - count($kept),
            'median' => round(self::percentile($kept, 50), 2),
            'p80' => round(self::percentile($kept, $planningPercentile), 2),
            'mean_raw' => round(array_sum($values) / count($values), 2),
        ];
    }

    /**
     * Persentil dengan interpolasi linear. $sorted wajib terurut naik.
     *
     * @param  list<float>  $sorted
     */
    public static function percentile(array $sorted, float $percentile): float
    {
        $count = count($sorted);

        if ($count === 1) {
            return $sorted[0];
        }

        $position = ($count - 1) * $percentile / 100;
        $lower = (int) floor($position);
        $upper = (int) ceil($position);

        return $sorted[$lower] + ($sorted[$upper] - $sorted[$lower]) * ($position - $lower);
    }
}
