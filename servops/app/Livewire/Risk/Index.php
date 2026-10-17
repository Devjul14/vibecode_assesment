<?php

namespace App\Livewire\Risk;

use App\Enums\RequestStatus;
use App\Enums\RiskLevel;
use App\Models\ServiceRequest;
use App\Services\Calendar\Clock;
use App\Services\Calendar\WorkingCalendar;
use App\Services\Workflow\HistoryRecorder;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Monitor SLA: membedakan tenggat, sisa effort, slack, batas mulai, proyeksi selesai, dan waktu tunggu.
 */
#[Title('Risiko SLA')]
class Index extends Component
{
    #[Url]
    public string $level = '';

    public function render(Clock $clock, WorkingCalendar $calendar, HistoryRecorder $history): View
    {
        $now = $clock->now();

        $requests = ServiceRequest::query()
            ->open()
            ->where('status', '!=', RequestStatus::New)
            ->when($this->level !== '', fn ($query) => $query->where('risk_level', $this->level))
            ->with('assignee')
            ->get()
            ->sortBy(fn (ServiceRequest $request) => [-($request->risk_level?->severity() ?? 0), $request->slack_hours ?? PHP_INT_MAX])
            ->values();

        return view('livewire.risk.index', [
            'requests' => $requests,
            'levels' => RiskLevel::cases(),
            'hoursLeft' => $requests->mapWithKeys(fn (ServiceRequest $request) => [$request->id => $calendar->workingHoursBetween($now, $request->deadline_at)]),
            'waitHours' => $requests->mapWithKeys(fn (ServiceRequest $request) => [$request->id => $history->currentWaitHours($request)]),
        ]);
    }
}
