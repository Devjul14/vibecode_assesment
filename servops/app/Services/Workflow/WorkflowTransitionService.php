<?php

namespace App\Services\Workflow;

use App\Data\Actor;
use App\Data\TransitionResult;
use App\Enums\DependencyType;
use App\Enums\RequestStatus as S;
use App\Enums\ReworkCause;
use App\Exceptions\WorkflowException;
use App\Models\EstimateRevision;
use App\Models\RequestDependency;
use App\Models\ServiceRequest;
use App\Models\TeamMember;
use App\Services\Calendar\Clock;
use App\Services\Calendar\WorkingCalendar;
use App\Services\Metrics\MetricsRecomputer;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Satu-satunya jalan untuk mengubah status request.
 * Guard → akumulasi waktu tunggu → efek samping → simpan → catat riwayat, semuanya dalam satu transaksi.
 */
class WorkflowTransitionService
{
    public function __construct(
        private TransitionGuard $guard,
        private HistoryRecorder $history,
        private Clock $clock,
        private WorkingCalendar $calendar,
        private MetricsRecomputer $recomputer,
    ) {}

    /**
     * @param  array{
     *     assignee_id?: int,
     *     pairing_member_id?: int|null,
     *     override_reason?: string|null,
     *     rework_estimate_hours?: float|int,
     *     rework_cause?: ReworkCause|string,
     *     dependency?: array{type?: DependencyType|string, depends_on_request_id?: int|null, external_party?: string|null, description?: string|null, expected_at?: mixed},
     * }  $payload
     *
     * @throws WorkflowException
     */
    public function transition(ServiceRequest $request, S $to, Actor $actor, ?string $reason = null, array $payload = []): TransitionResult
    {
        $result = DB::transaction(function () use ($request, $to, $actor, $reason, $payload): TransitionResult {
            $request = ServiceRequest::query()->lockForUpdate()->findOrFail($request->id);
            $from = $request->status;

            $warnings = $this->guard->check($request, $to, $actor, $reason, $payload);
            $now = $this->clock->now();

            $waitHours = $this->accumulateWaitTime($request, $from, $now);
            $meta = $this->applyEffects($request, $from, $to, $actor, $payload, $now);

            $request->status = $to;
            $request->save();

            $this->history->record($request, $from, $to, $actor, $reason, array_filter([
                ...$meta,
                'wait_hours' => $waitHours ?: null,
                'warnings' => $warnings ?: null,
            ], fn ($value) => $value !== null));

            return new TransitionResult($request->refresh(), $warnings);
        });

        $this->recomputer->recomputeIfEnabled();

        return new TransitionResult($result->request->refresh(), $result->warnings);
    }

    /**
     * Status tunggu (ready, assigned, blocked, delivered) menambah wait_hours_total dalam jam kerja,
     * sehingga waktu tunggu dapat dibedakan dari waktu pengerjaan aktif.
     */
    private function accumulateWaitTime(ServiceRequest $request, S $from, CarbonImmutable $now): float
    {
        if (! $from->isWaiting()) {
            return 0.0;
        }

        $enteredAt = $this->history->enteredCurrentStatusAt($request);

        if ($enteredAt === null) {
            return 0.0;
        }

        $hours = max(0.0, $this->calendar->workingHoursBetween($enteredAt, $now));
        $request->wait_hours_total = round($request->wait_hours_total + $hours, 2);

        return $hours;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed> meta untuk riwayat
     */
    private function applyEffects(ServiceRequest $request, S $from, S $to, Actor $actor, array $payload, CarbonImmutable $now): array
    {
        return match (true) {
            $to === S::Ready && $from === S::Triage => $this->markReady($request),
            $to === S::Ready && $from === S::Assigned => $this->unassign($request),
            $to === S::Assigned => $this->assign($request, $payload),
            $to === S::InProgress => $this->start($request, $from, $now),
            $to === S::Blocked => $this->block($request, $payload, $now),
            $to === S::Delivered => $this->deliver($request, $now),
            $to === S::Accepted => $this->accept($request, $now),
            $to === S::Returned => $this->returnForRework($request, $actor, $payload, $now),
            default => [],
        };
    }

    /** @return array<string, mixed> */
    private function markReady(ServiceRequest $request): array
    {
        $request->remaining_hours ??= $request->estimate_hours;

        return [];
    }

    /** @return array<string, mixed> */
    private function unassign(ServiceRequest $request): array
    {
        $meta = ['previous_assignee' => $request->assignee?->name];
        $request->assignee_id = null;
        $request->pairing_member_id = null;

        return $meta;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function assign(ServiceRequest $request, array $payload): array
    {
        $assignee = TeamMember::query()->findOrFail($payload['assignee_id']);
        $request->assignee_id = $assignee->id;
        $request->pairing_member_id = $assignee->isQualifiedFor($request) ? null : ($payload['pairing_member_id'] ?? null);

        return array_filter([
            'assignee' => $assignee->name,
            'pairing_with' => $request->pairing_member_id ? TeamMember::query()->find($request->pairing_member_id)?->name : null,
            'override_reason' => $request->pairing_member_id ? ($payload['override_reason'] ?? null) : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function start(ServiceRequest $request, S $from, CarbonImmutable $now): array
    {
        $request->started_at ??= $now;

        if ($from === S::Blocked) {
            $meta = ['blocked_hours' => $request->blocked_since ? $this->calendar->workingHoursBetween($request->blocked_since, $now) : null];
            $request->blocked_since = null;

            return array_filter($meta);
        }

        return $from === S::Returned ? ['rework_started' => true] : [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function block(ServiceRequest $request, array $payload, CarbonImmutable $now): array
    {
        $request->blocked_since = $now;

        if (empty($payload['dependency'])) {
            return [];
        }

        $data = $payload['dependency'];
        $dependency = RequestDependency::create([
            'service_request_id' => $request->id,
            'depends_on_request_id' => $data['depends_on_request_id'] ?? null,
            'type' => $data['type'] ?? (empty($data['depends_on_request_id']) ? DependencyType::External : DependencyType::Internal),
            'external_party' => $data['external_party'] ?? null,
            'description' => $data['description'] ?? null,
            'expected_at' => $data['expected_at'] ?? null,
        ]);

        return ['dependency_id' => $dependency->id];
    }

    /** @return array<string, mixed> */
    private function deliver(ServiceRequest $request, CarbonImmutable $now): array
    {
        $request->delivered_at = $now;
        $request->remaining_hours = 0;

        return [];
    }

    /** @return array<string, mixed> */
    private function accept(ServiceRequest $request, CarbonImmutable $now): array
    {
        $request->accepted_at = $now;
        $request->remaining_hours = 0;

        return [
            'on_time' => $request->delivered_at?->lessThanOrEqualTo($request->deadline_at),
        ];
    }

    /**
     * Rework menambah beban: sisa effort = estimasi rework, estimasi total bertambah,
     * dan tercatat sebagai revisi bertipe "rework" (bukan pembengkakan scope).
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function returnForRework(ServiceRequest $request, Actor $actor, array $payload, CarbonImmutable $now): array
    {
        $reworkHours = round((float) $payload['rework_estimate_hours'], 2);
        $cause = $payload['rework_cause'] instanceof ReworkCause
            ? $payload['rework_cause']
            : ReworkCause::from($payload['rework_cause']);
        $oldEstimate = (float) $request->estimate_hours;

        $request->rework_count++;
        $request->rework_cause = $cause;
        $request->remaining_hours = $reworkHours;
        $request->estimate_hours = $oldEstimate + $reworkHours;

        EstimateRevision::create([
            'service_request_id' => $request->id,
            'old_hours' => $oldEstimate,
            'new_hours' => $request->estimate_hours,
            'delta_pct' => $oldEstimate > 0 ? round($reworkHours / $oldEstimate * 100, 2) : null,
            'kind' => 'rework',
            'scope_change' => false,
            'reason' => "Rework {$reworkHours} jam: {$cause->label()}",
            'actor_role' => $actor->role,
            'actor_name' => $actor->name,
            'created_at' => $now,
        ]);

        return [
            'rework_hours' => $reworkHours,
            'rework_cause' => $cause->value,
            'rework_count' => $request->rework_count,
        ];
    }
}
