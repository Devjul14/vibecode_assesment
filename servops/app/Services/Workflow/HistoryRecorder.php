<?php

namespace App\Services\Workflow;

use App\Data\Actor;
use App\Enums\RequestStatus;
use App\Models\ServiceRequest;
use App\Models\StatusHistory;
use App\Services\Calendar\Clock;
use App\Services\Calendar\WorkingCalendar;
use Carbon\CarbonInterface;

/**
 * Satu timeline audit per request. Perubahan non-status (estimasi, reassign, dependensi)
 * dicatat dengan from = to = status saat ini dan meta.event.
 */
class HistoryRecorder
{
    public function __construct(
        private Clock $clock,
        private WorkingCalendar $calendar,
    ) {}

    /**
     * @param  array<string, mixed>  $meta
     */
    public function record(
        ServiceRequest $request,
        ?RequestStatus $from,
        RequestStatus $to,
        Actor $actor,
        ?string $reason = null,
        array $meta = [],
    ): StatusHistory {
        return StatusHistory::create([
            'service_request_id' => $request->id,
            'from_status' => $from,
            'to_status' => $to,
            'actor_role' => $actor->role,
            'actor_name' => $actor->name,
            'reason' => $reason,
            'meta' => $meta === [] ? null : $meta,
            'created_at' => $this->clock->now(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function event(ServiceRequest $request, string $event, Actor $actor, ?string $reason = null, array $meta = []): StatusHistory
    {
        return $this->record($request, $request->status, $request->status, $actor, $reason, ['event' => $event, ...$meta]);
    }

    /** Waktu masuk ke status saat ini (entri riwayat terakhir yang benar-benar mengubah status). */
    public function enteredCurrentStatusAt(ServiceRequest $request): ?CarbonInterface
    {
        return StatusHistory::query()
            ->where('service_request_id', $request->id)
            ->where('to_status', $request->status)
            ->where(fn ($query) => $query->whereNull('from_status')->orWhereColumn('from_status', '!=', 'to_status'))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->value('created_at');
    }

    /**
     * Total waktu tunggu termasuk yang sedang berjalan (status tunggu saat ini belum ditutup transisi).
     */
    public function currentWaitHours(ServiceRequest $request): float
    {
        $total = (float) $request->wait_hours_total;

        if (! $request->status->isWaiting()) {
            return $total;
        }

        $enteredAt = $this->enteredCurrentStatusAt($request);

        return $enteredAt === null
            ? $total
            : round($total + max(0.0, $this->calendar->workingHoursBetween($enteredAt, $this->clock->now())), 2);
    }
}
