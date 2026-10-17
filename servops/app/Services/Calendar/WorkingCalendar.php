<?php

namespace App\Services\Calendar;

use App\Models\Holiday;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Aritmatika jam kerja: SLA dan kapasitas dihitung dalam jam kerja, bukan jam kalender.
 */
class WorkingCalendar
{
    private const MAX_DAYS_SCANNED = 3650;

    /** @var array<string, true>|null */
    private ?array $holidays;

    /**
     * @param  array{work_start: string, work_end: string, break_start: string, break_end: string, work_days: list<int>}  $config
     * @param  list<string>|null  $holidays  Tanggal libur (Y-m-d). Null = dimuat dari tabel holidays saat dibutuhkan.
     */
    public function __construct(private array $config, ?array $holidays = null)
    {
        $this->holidays = $holidays === null ? null : array_fill_keys($holidays, true);
    }

    public function isWorkingDay(CarbonInterface $day): bool
    {
        return in_array($day->dayOfWeekIso, $this->config['work_days'], true)
            && ! isset($this->holidays()[$day->format('Y-m-d')]);
    }

    /**
     * Segmen jam kerja dalam satu hari (sebelum dan sesudah istirahat).
     *
     * @return list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public function segmentsFor(CarbonInterface $day): array
    {
        if (! $this->isWorkingDay($day)) {
            return [];
        }

        $at = fn (string $time): CarbonImmutable => CarbonImmutable::parse($day->format('Y-m-d').' '.$time, $day->getTimezone());

        return [
            [$at($this->config['work_start']), $at($this->config['break_start'])],
            [$at($this->config['break_end']), $at($this->config['work_end'])],
        ];
    }

    public function hoursPerDay(): float
    {
        $reference = CarbonImmutable::parse('2024-01-01'); // Senin

        return array_sum(array_map(
            fn (array $segment): float => $segment[0]->diffInSeconds($segment[1]) / 3600,
            $this->segmentsFor($reference),
        ));
    }

    /** Jam kerja di antara dua waktu. Negatif jika $to sebelum $from. */
    public function workingHoursBetween(CarbonInterface $from, CarbonInterface $to): float
    {
        if ($to->lessThan($from)) {
            return -$this->workingHoursBetween($to, $from);
        }

        $from = CarbonImmutable::instance($from);
        $to = CarbonImmutable::instance($to);
        $seconds = 0;

        for ($day = $from->startOfDay(); $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            foreach ($this->segmentsFor($day) as [$segmentStart, $segmentEnd]) {
                $start = $segmentStart->max($from);
                $end = $segmentEnd->min($to);

                if ($end->greaterThan($start)) {
                    $seconds += $start->diffInSeconds($end);
                }
            }
        }

        return round($seconds / 3600, 2);
    }

    /** Waktu setelah menambahkan sejumlah jam kerja. */
    public function addWorkingHours(CarbonInterface $from, float $hours): CarbonImmutable
    {
        if ($hours < 0) {
            return $this->subWorkingHours($from, -$hours);
        }

        $cursor = CarbonImmutable::instance($from);
        $remaining = (int) round($hours * 3600);

        for ($i = 0, $day = $cursor->startOfDay(); $i < self::MAX_DAYS_SCANNED; $i++, $day = $day->addDay()) {
            foreach ($this->segmentsFor($day) as [$segmentStart, $segmentEnd]) {
                $start = $segmentStart->max($cursor);

                if ($segmentEnd->lessThanOrEqualTo($start)) {
                    continue;
                }

                $available = $start->diffInSeconds($segmentEnd);

                if ($remaining <= $available) {
                    return $start->addSeconds($remaining);
                }

                $remaining -= $available;
            }
        }

        throw new LogicException('Tidak ada hari kerja dalam rentang kalender.');
    }

    /** Waktu sebelum $from dikurangi sejumlah jam kerja (untuk latest start). */
    public function subWorkingHours(CarbonInterface $from, float $hours): CarbonImmutable
    {
        if ($hours < 0) {
            return $this->addWorkingHours($from, -$hours);
        }

        $cursor = CarbonImmutable::instance($from);
        $remaining = (int) round($hours * 3600);

        for ($i = 0, $day = $cursor->startOfDay(); $i < self::MAX_DAYS_SCANNED; $i++, $day = $day->subDay()) {
            foreach (array_reverse($this->segmentsFor($day)) as [$segmentStart, $segmentEnd]) {
                $end = $segmentEnd->min($cursor);

                if ($end->lessThanOrEqualTo($segmentStart)) {
                    continue;
                }

                $available = $segmentStart->diffInSeconds($end);

                if ($remaining <= $available) {
                    return $end->subSeconds($remaining);
                }

                $remaining -= $available;
            }
        }

        throw new LogicException('Tidak ada hari kerja dalam rentang kalender.');
    }

    /** Momen kerja pertama pada atau setelah $at. */
    public function nextWorkingMoment(CarbonInterface $at): CarbonImmutable
    {
        return $this->addWorkingHours($at, 0);
    }

    /** Akhir jam kerja pada hari kerja ke-$days setelah $from (0 = hari kerja ini / berikutnya). */
    public function endOfWorkingDay(CarbonInterface $from, int $days = 0): CarbonImmutable
    {
        $day = $this->nextWorkingMoment($from)->startOfDay();

        while ($days > 0) {
            $day = $day->addDay();

            if ($this->isWorkingDay($day)) {
                $days--;
            }
        }

        return $this->segmentsFor($day)[1][1];
    }

    /** @return array<string, true> */
    private function holidays(): array
    {
        if ($this->holidays === null) {
            $this->holidays = Schema::hasTable('holidays')
                ? array_fill_keys(
                    Holiday::query()->get(['date'])->map(fn (Holiday $holiday): string => $holiday->date->format('Y-m-d'))->all(),
                    true,
                )
                : [];
        }

        return $this->holidays;
    }

    public function flushHolidayCache(): void
    {
        $this->holidays = null;
    }
}
