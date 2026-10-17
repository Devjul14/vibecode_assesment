<?php

namespace Database\Seeders\Support;

use App\Enums\ActorRole;
use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\StatusHistory;
use App\Services\Calendar\WorkingCalendar;
use Carbon\CarbonImmutable;

/**
 * Titik acuan waktu seed: semua tanggal dihitung relatif terhadap "sekarang" dalam jam kerja.
 */
final class Timeline
{
    public function __construct(
        public readonly CarbonImmutable $now,
        public readonly WorkingCalendar $calendar,
    ) {}

    /** Akhir jam kerja pada hari kerja ke-N dari sekarang (0 = hari ini). */
    public function endOfDay(int $workingDaysFromNow): CarbonImmutable
    {
        return $this->calendar->endOfWorkingDay($this->now, $workingDaysFromNow);
    }

    public function hoursAgo(float $workingHours): CarbonImmutable
    {
        return $this->calendar->subWorkingHours($this->now, $workingHours);
    }

    public function hoursFromNow(float $workingHours): CarbonImmutable
    {
        return $this->calendar->addWorkingHours($this->now, $workingHours);
    }

    /**
     * Tulis jejak status berurutan dan set status akhir request.
     *
     * @param  list<array{0: RequestStatus, 1: CarbonImmutable, 2?: ActorRole, 3?: string|null, 4?: string|null}>  $steps
     */
    public function recordHistory(ServiceRequest $request, array $steps): void
    {
        $previous = null;

        foreach ($steps as $step) {
            [$status, $at] = $step;

            StatusHistory::create([
                'service_request_id' => $request->id,
                'from_status' => $previous,
                'to_status' => $status,
                'actor_role' => $step[2] ?? ActorRole::System,
                'actor_name' => $step[3] ?? null,
                'reason' => $step[4] ?? null,
                'created_at' => $at,
            ]);

            $previous = $status;
        }

        $request->forceFill([
            'status' => $previous,
            'created_at' => $steps[0][1],
            'updated_at' => end($steps)[1],
        ])->save();
    }
}
