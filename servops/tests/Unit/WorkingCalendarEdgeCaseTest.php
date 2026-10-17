<?php

namespace Tests\Unit;

use App\Services\Calendar\WorkingCalendar;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Kasus batas kalender kerja (T3.3): batas jam kerja, zona waktu, rentang panjang.
 */
class WorkingCalendarEdgeCaseTest extends TestCase
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
        ], holidays: ['2026-10-21', '2026-12-24', '2026-12-25']);
    }

    private function at(string $datetime): CarbonImmutable
    {
        return CarbonImmutable::parse($datetime, 'Asia/Jakarta');
    }

    /**
     * @return array<string, array{0: string, 1: float, 2: string}>
     */
    public static function addProvider(): array
    {
        return [
            'mulai saat istirahat' => ['2026-10-19 12:30', 1, '2026-10-19 14:00'],
            'mulai sebelum jam kerja' => ['2026-10-19 06:00', 1, '2026-10-19 09:00'],
            'mulai setelah jam kerja' => ['2026-10-19 19:00', 1, '2026-10-20 09:00'],
            'tepat habis sebelum istirahat' => ['2026-10-19 11:00', 1, '2026-10-19 12:00'],
            'jumat sore lintas weekend' => ['2026-10-23 16:30', 1, '2026-10-26 08:30'],
            'mulai sabtu' => ['2026-10-24 10:00', 2, '2026-10-26 10:00'],
            'pecahan jam' => ['2026-10-19 08:00', 0.25, '2026-10-19 08:15'],
            'lompati libur rabu' => ['2026-10-20 16:00', 2, '2026-10-22 09:00'],
            'libur natal berurutan' => ['2026-12-23 16:00', 2, '2026-12-28 09:00'],
        ];
    }

    #[DataProvider('addProvider')]
    public function test_add_working_hours(string $from, float $hours, string $expected): void
    {
        $this->assertSame($expected, $this->calendar->addWorkingHours($this->at($from), $hours)->format('Y-m-d H:i'));
    }

    public function test_hours_between_ignores_time_outside_working_window(): void
    {
        $this->assertSame(0.0, $this->calendar->workingHoursBetween($this->at('2026-10-19 12:00'), $this->at('2026-10-19 13:00')));
        $this->assertSame(0.0, $this->calendar->workingHoursBetween($this->at('2026-10-24 08:00'), $this->at('2026-10-25 17:00')));
        $this->assertSame(0.0, $this->calendar->workingHoursBetween($this->at('2026-10-21 08:00'), $this->at('2026-10-21 17:00')));
        $this->assertSame(8.0, $this->calendar->workingHoursBetween($this->at('2026-10-19 00:00'), $this->at('2026-10-19 23:59')));
    }

    public function test_long_range_add_and_sub_are_symmetric(): void
    {
        $start = $this->at('2026-10-19 09:30');
        $later = $this->calendar->addWorkingHours($start, 400);

        $this->assertSame(400.0, $this->calendar->workingHoursBetween($start, $later));
        $this->assertTrue($this->calendar->subWorkingHours($later, 400)->equalTo($start));
    }

    public function test_end_of_working_day_skips_holidays(): void
    {
        $this->assertSame('2026-10-22 17:00', $this->calendar->endOfWorkingDay($this->at('2026-10-20 09:00'), 1)->format('Y-m-d H:i'));
    }
}
