<?php

namespace Tests\Unit;

use App\Services\Calendar\WorkingCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class WorkingCalendarTest extends TestCase
{
    private WorkingCalendar $calendar;

    protected function setUp(): void
    {
        parent::setUp();

        $this->calendar = new WorkingCalendar([
            'work_start' => '08:00',
            'work_end' => '17:00',
            'break_start' => '12:00',
            'break_end' => '13:00',
            'work_days' => [1, 2, 3, 4, 5],
        ], holidays: ['2026-10-21']);
    }

    private function at(string $datetime): CarbonImmutable
    {
        return CarbonImmutable::parse($datetime, 'Asia/Jakarta');
    }

    public function test_hours_per_day_excludes_lunch_break(): void
    {
        $this->assertSame(8.0, $this->calendar->hoursPerDay());
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: float}>
     */
    public static function hoursBetweenProvider(): array
    {
        return [
            'same morning' => ['2026-10-19 09:00', '2026-10-19 11:30', 2.5],
            'across lunch' => ['2026-10-19 11:00', '2026-10-19 14:00', 2.0],
            'full day' => ['2026-10-19 00:00', '2026-10-19 23:59', 8.0],
            'over weekend' => ['2026-10-16 16:00', '2026-10-19 09:00', 2.0],
            'skips holiday' => ['2026-10-20 17:00', '2026-10-22 09:00', 1.0],
            'reversed is negative' => ['2026-10-19 11:30', '2026-10-19 09:00', -2.5],
        ];
    }

    #[DataProvider('hoursBetweenProvider')]
    public function test_working_hours_between(string $from, string $to, float $expected): void
    {
        $this->assertSame($expected, $this->calendar->workingHoursBetween($this->at($from), $this->at($to)));
    }

    public function test_add_working_hours_skips_lunch_weekend_and_holiday(): void
    {
        $this->assertEquals($this->at('2026-10-19 14:00'), $this->calendar->addWorkingHours($this->at('2026-10-19 11:00'), 2));
        $this->assertEquals($this->at('2026-10-19 10:00'), $this->calendar->addWorkingHours($this->at('2026-10-16 16:00'), 3));
        $this->assertEquals($this->at('2026-10-22 09:00'), $this->calendar->addWorkingHours($this->at('2026-10-20 16:00'), 2));
    }

    public function test_sub_working_hours_is_inverse_of_add(): void
    {
        $from = $this->at('2026-10-19 10:00');

        $this->assertEquals($this->at('2026-10-16 15:00'), $this->calendar->subWorkingHours($from, 4));
        $this->assertEquals($from, $this->calendar->addWorkingHours($this->calendar->subWorkingHours($from, 13.5), 13.5));
    }

    public function test_next_working_moment_moves_weekend_to_monday_morning(): void
    {
        $this->assertEquals($this->at('2026-10-19 08:00'), $this->calendar->nextWorkingMoment($this->at('2026-10-17 10:00')));
        $this->assertEquals($this->at('2026-10-19 13:00'), $this->calendar->nextWorkingMoment($this->at('2026-10-19 12:15')));
    }

    public function test_end_of_working_day_counts_only_working_days(): void
    {
        $monday = $this->at('2026-10-19 09:00');

        $this->assertEquals($this->at('2026-10-19 17:00'), $this->calendar->endOfWorkingDay($monday));
        $this->assertEquals($this->at('2026-10-22 17:00'), $this->calendar->endOfWorkingDay($monday, 2));
        $this->assertEquals($this->at('2026-10-19 17:00'), $this->calendar->endOfWorkingDay($this->at('2026-10-17 10:00')));
    }
}
