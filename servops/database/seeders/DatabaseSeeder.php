<?php

namespace Database\Seeders;

use App\Services\Calendar\Clock;
use App\Services\Calendar\WorkingCalendar;
use App\Services\Metrics\MetricsRecomputer;
use Carbon\CarbonImmutable;
use Database\Seeders\Support\Timeline;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed data simulasi ServOps.
     *
     * Waktu simulasi dibekukan pada momen kerja terdekat agar demo deterministik:
     * jika sekarang di luar jam kerja (mis. akhir pekan), acuan = hari kerja berikutnya pukul 09:00.
     */
    public function run(Clock $clock, WorkingCalendar $calendar): void
    {
        $this->call(ReferenceDataSeeder::class);
        $calendar->flushHolidayCache();

        $timeline = new Timeline($this->resolveAnchor($clock, $calendar), $calendar);
        $clock->freeze($timeline->now);
        $this->container->instance(Timeline::class, $timeline);

        $this->call([
            TeamSeeder::class,
            UserSeeder::class,
            HistoricalRequestSeeder::class,
            CurrentWorkloadSeeder::class,
        ]);

        $summary = app(MetricsRecomputer::class)->run();

        $this->command?->info("Waktu simulasi dibekukan pada {$timeline->now->translatedFormat('l, d M Y H:i')}.");
        $this->command?->info("Metrik dihitung: {$summary['requests']} request terbuka, {$summary['at_risk']} berisiko, {$summary['recommendations']['created']} rekomendasi.");
    }

    private function resolveAnchor(Clock $clock, WorkingCalendar $calendar): CarbonImmutable
    {
        $clock->release();

        if (filled(config('servops.now'))) {
            return $clock->now();
        }

        $now = CarbonImmutable::now()->startOfMinute();
        $anchor = $calendar->nextWorkingMoment($now);

        if ($anchor->notEqualTo($now)) {
            $anchor = $calendar->addWorkingHours($anchor, 1);
        }

        return $anchor;
    }
}
