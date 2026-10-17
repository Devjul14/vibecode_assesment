<?php

namespace App\Services\Workflow;

use App\Data\Actor;
use App\Data\TransitionResult;
use App\Enums\DependencyType;
use App\Enums\ImpactLevel;
use App\Enums\RequestStatus as S;
use App\Exceptions\WorkflowException;
use App\Models\EstimateRevision;
use App\Models\RequestDependency;
use App\Models\ServiceRequest;
use App\Models\TeamMember;
use App\Services\Calendar\Clock;
use App\Services\Metrics\MetricsRecomputer;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

/**
 * Perubahan yang bukan transisi status: revisi estimasi, pemindahan penugasan, dan dependensi.
 * Semua wajib beralasan dan tercatat di timeline riwayat (BLUEPRINT §4.4).
 */
class RequestChangeService
{
    /** Status di mana penugasan boleh dipindahkan tanpa mengubah status. */
    private const REASSIGNABLE = [S::Assigned, S::InProgress, S::Blocked, S::Returned];

    public function __construct(
        private TransitionGuard $guard,
        private HistoryRecorder $history,
        private Clock $clock,
        private MetricsRecomputer $recomputer,
    ) {}

    /**
     * Revisi estimasi total. Sisa effort ikut bergeser sebesar selisihnya.
     * Kenaikan besar ditandai engine prioritas/risiko (fase 4) untuk ditinjau manusia.
     *
     * @throws WorkflowException
     */
    public function reviseEstimate(ServiceRequest $request, float $newHours, string $reason, Actor $actor, bool $scopeChange = false): EstimateRevision
    {
        $this->assertOpen($request);

        if ($newHours <= 0) {
            throw WorkflowException::invalidEstimate();
        }

        if (blank($reason)) {
            throw WorkflowException::reasonRequired('revisi estimasi');
        }

        $result = DB::transaction(function () use ($request, $newHours, $reason, $actor, $scopeChange): EstimateRevision {
            $oldHours = $request->estimate_hours;
            $delta = $oldHours === null ? null : $newHours - $oldHours;

            $request->estimate_hours = $newHours;
            $request->remaining_hours = match (true) {
                $oldHours === null, $request->remaining_hours === null => $newHours,
                default => max(0.0, round($request->remaining_hours + $delta, 2)),
            };
            $request->save();

            $revision = EstimateRevision::create([
                'service_request_id' => $request->id,
                'old_hours' => $oldHours,
                'new_hours' => $newHours,
                'delta_pct' => $oldHours ? round($delta / $oldHours * 100, 2) : null,
                'kind' => $oldHours === null ? 'initial' : 'revision',
                'scope_change' => $scopeChange,
                'reason' => $reason,
                'actor_role' => $actor->role,
                'actor_name' => $actor->name,
                'created_at' => $this->clock->now(),
            ]);

            $this->history->event($request, 'estimate_revised', $actor, $reason, array_filter([
                'old_hours' => $oldHours,
                'new_hours' => $newHours,
                'delta_pct' => $revision->delta_pct,
                'scope_change' => $scopeChange ?: null,
            ], fn ($value) => $value !== null));

            return $revision;
        });

        $this->recomputer->recomputeIfEnabled();

        return $result;
    }

    /**
     * Pindahkan penugasan (mis. anggota mendadak tidak tersedia) dengan aturan kompetensi yang sama.
     *
     * @throws WorkflowException
     */
    public function reassign(
        ServiceRequest $request,
        TeamMember $member,
        string $reason,
        Actor $actor,
        ?int $pairingMemberId = null,
        ?string $overrideReason = null,
    ): TransitionResult {
        if (! in_array($request->status, self::REASSIGNABLE, true)) {
            throw WorkflowException::notReassignable($request->status);
        }

        if (blank($reason)) {
            throw WorkflowException::reasonRequired('pemindahan penugasan');
        }

        $warnings = $this->guard->assertAssignable($request, $member, [
            'pairing_member_id' => $pairingMemberId,
            'override_reason' => $overrideReason,
        ]);

        $result = DB::transaction(function () use ($request, $member, $reason, $actor, $pairingMemberId, $overrideReason, $warnings): TransitionResult {
            $previous = $request->assignee?->name;
            $qualified = $member->isQualifiedFor($request);

            $request->assignee_id = $member->id;
            $request->pairing_member_id = $qualified ? null : $pairingMemberId;
            $request->save();

            $this->history->event($request, 'reassigned', $actor, $reason, array_filter([
                'from_assignee' => $previous,
                'to_assignee' => $member->name,
                'pairing_with' => $qualified ? null : TeamMember::query()->find($pairingMemberId)?->name,
                'override_reason' => $qualified ? null : $overrideReason,
                'warnings' => $warnings ?: null,
            ]));

            return new TransitionResult($request->refresh(), $warnings);
        });

        $this->recomputer->recomputeIfEnabled();

        return $result;
    }

    /**
     * @param  array{type?: DependencyType|string, depends_on_request_id?: int|null, external_party?: string|null, description?: string|null, expected_at?: mixed}  $data
     *
     * @throws WorkflowException
     */
    public function addDependency(ServiceRequest $request, array $data, Actor $actor): RequestDependency
    {
        $this->assertOpen($request);

        $result = DB::transaction(function () use ($request, $data, $actor): RequestDependency {
            $dependency = RequestDependency::create([
                'service_request_id' => $request->id,
                'depends_on_request_id' => $data['depends_on_request_id'] ?? null,
                'type' => $data['type'] ?? (empty($data['depends_on_request_id']) ? DependencyType::External : DependencyType::Internal),
                'external_party' => $data['external_party'] ?? null,
                'description' => $data['description'] ?? null,
                'expected_at' => $data['expected_at'] ?? null,
            ]);

            $this->history->event($request, 'dependency_added', $actor, $dependency->description, [
                'dependency_id' => $dependency->id,
                'party' => $dependency->external_party ?? $dependency->dependsOn?->code,
            ]);

            return $dependency;
        });

        $this->recomputer->recomputeIfEnabled();

        return $result;
    }

    /**
     * Tandai dependensi selesai. Request tidak otomatis dilanjutkan — anggota tim yang memulai kembali.
     */
    public function resolveDependency(RequestDependency $dependency, Actor $actor, ?string $note = null): RequestDependency
    {
        $result = DB::transaction(function () use ($dependency, $actor, $note): RequestDependency {
            $dependency->resolved_at = $this->clock->now();
            $dependency->save();

            $request = $dependency->serviceRequest;
            $this->history->event($request, 'dependency_resolved', $actor, $note, [
                'dependency_id' => $dependency->id,
                'party' => $dependency->external_party ?? $dependency->dependsOn?->code,
                'remaining_open' => $request->openDependencies()->count(),
            ]);

            return $dependency;
        });

        $this->recomputer->recomputeIfEnabled();

        return $result;
    }

    /**
     * Hasil triage dampak: tingkat dampak, departemen & pengguna terdampak, proses bisnis.
     *
     * @throws WorkflowException
     */
    public function assessImpact(
        ServiceRequest $request,
        ImpactLevel $impact,
        ?int $affectedDepartments,
        ?int $affectedUsers,
        string $reason,
        Actor $actor,
        ?int $businessProcessId = null,
    ): ServiceRequest {
        $this->assertOpen($request);

        if (blank($reason)) {
            throw WorkflowException::reasonRequired('penilaian dampak');
        }

        $result = DB::transaction(function () use ($request, $impact, $affectedDepartments, $affectedUsers, $reason, $actor, $businessProcessId): ServiceRequest {
            $before = $request->impact_level;

            $request->forceFill(array_filter([
                'impact_level' => $impact,
                'affected_departments' => $affectedDepartments,
                'affected_users' => $affectedUsers,
                'business_process_id' => $businessProcessId,
            ], fn ($value) => $value !== null))->save();

            $this->history->event($request, 'impact_assessed', $actor, $reason, array_filter([
                'from' => $before->label(),
                'to' => $impact->label(),
                'affected_departments' => $affectedDepartments,
                'affected_users' => $affectedUsers,
            ], fn ($value) => $value !== null));

            return $request;
        });

        $this->recomputer->recomputeIfEnabled();

        return $result->refresh();
    }

    /**
     * ETA dependensi berubah (mis. tim lain mengabarkan keterlambatan).
     */
    public function rescheduleDependency(RequestDependency $dependency, CarbonInterface $expectedAt, string $reason, Actor $actor): RequestDependency
    {
        if (blank($reason)) {
            throw WorkflowException::reasonRequired('perubahan ETA dependensi');
        }

        $result = DB::transaction(function () use ($dependency, $expectedAt, $reason, $actor): RequestDependency {
            $previous = $dependency->expected_at;
            $dependency->update(['expected_at' => $expectedAt]);

            $this->history->event($dependency->serviceRequest, 'dependency_rescheduled', $actor, $reason, [
                'party' => $dependency->external_party ?? $dependency->dependsOn?->code,
                'from' => $previous?->format('Y-m-d H:i'),
                'to' => $expectedAt->format('Y-m-d H:i'),
            ]);

            return $dependency;
        });

        $this->recomputer->recomputeIfEnabled();

        return $result;
    }

    private function assertOpen(ServiceRequest $request): void
    {
        if (! $request->status->isOpen()) {
            throw WorkflowException::requestClosed();
        }
    }
}
