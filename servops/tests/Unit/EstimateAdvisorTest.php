<?php

namespace Tests\Unit;

use App\Services\Estimation\EstimateAdvisor;
use PHPUnit\Framework\TestCase;

class EstimateAdvisorTest extends TestCase
{
    public function test_percentile_interpolates_linearly(): void
    {
        $values = [1.0, 2.0, 3.0, 4.0, 5.0];

        $this->assertSame(3.0, EstimateAdvisor::percentile($values, 50));
        $this->assertSame(4.2, EstimateAdvisor::percentile($values, 80));
        $this->assertSame(1.0, EstimateAdvisor::percentile($values, 0));
        $this->assertSame(7.0, EstimateAdvisor::percentile([7.0], 80));
    }

    public function test_extreme_outlier_is_removed_and_does_not_distort_median(): void
    {
        $summary = EstimateAdvisor::summarize([5.0, 6.0, 6.0, 7.0, 7.0, 8.0, 40.0]);

        $this->assertSame(1, $summary['outliers']);
        $this->assertSame(6, $summary['n']);
        $this->assertSame(6.5, $summary['median']);
        $this->assertLessThan(8.0, $summary['p80']);
        $this->assertGreaterThan(11.0, $summary['mean_raw'], 'Rata-rata mentah terdistorsi outlier — alasan memakai median/P80');
    }

    public function test_empty_values_return_null(): void
    {
        $this->assertNull(EstimateAdvisor::summarize([]));
    }
}
