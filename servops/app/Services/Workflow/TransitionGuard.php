<?php

namespace App\Services\Workflow;

use App\Data\Actor;
use App\Enums\RequestStatus as S;
use App\Enums\ReworkCause;
use App\Exceptions\WorkflowException;
use App\Models\CapacityEntry;
use App\Models\ServiceRequest;
use App\Models\TeamMember;
use App\Services\Calendar\Clock;

/**
 * Aturan bisnis setiap transisi status (BLUEPRINT §4.3).
 * Melempar WorkflowException bila ditolak; mengembalikan peringatan bila boleh lanjut.
 */
class TransitionGuard
{
    public function __construct(private Clock $clock) {}

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{code: string, message: string}>
     *
     * @throws WorkflowException
     */
    public function check(ServiceRequest $request, S $to, Actor $actor, ?string $reason, array $payload): array
    {
        $from = $request->status;

        if (! $from->canTransitionTo($to)) {
            throw WorkflowException::transitionNotAllowed($from, $to);
        }

        return match (true) {
            $to === S::Cancelled => $this->requireReason($reason, 'pembatalan'),
            $to === S::NeedsClarification => $this->requireReason($reason, 'permintaan klarifikasi'),
            $from === S::Triage && $to === S::Ready => $this->checkReady($request),
            $to === S::Assigned => $this->checkAssignment($request, $payload),
            $from === S::Assigned && $to === S::InProgress => $this->checkStart($request),
            $from === S::Returned && $to === S::InProgress => $this->checkResumeRework($request),
            $to === S::Blocked => $this->checkBlock($reason, $payload),
            $from === S::Blocked && $to === S::InProgress => $this->checkUnblock($request),
            $to === S::Accepted => $this->checkAcceptance($actor),
            $to === S::Returned => $this->checkReturn($reason, $payload),
            $from === S::InProgress && $to === S::Triage => $this->requireReason($reason, 'pengembalian ke triage karena perubahan scope'),
            default => [],
        };
    }

    /**
     * Penugasan harus ke anggota aktif, tersedia, dan kompeten —
     * atau di bawah syarat dengan mentor yang kompeten + alasan override.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{code: string, message: string}>
     *
     * @throws WorkflowException
     */
    public function assertAssignable(ServiceRequest $request, TeamMember $member, array $payload = []): array
    {
        if (! $member->is_active) {
            throw WorkflowException::memberInactive($member->name);
        }

        $absence = $this->currentAbsence($member);

        if ($absence !== null) {
            throw WorkflowException::memberUnavailable($member->name, $absence->type->label());
        }

        if ($member->isQualifiedFor($request)) {
            return [];
        }

        $mentorId = $payload['pairing_member_id'] ?? null;
        $overrideReason = trim((string) ($payload['override_reason'] ?? ''));

        if ($mentorId === null || $overrideReason === '') {
            throw WorkflowException::underqualified(
                $member->name,
                $member->skillLevel($request->required_skill_id),
                $request->required_level,
            );
        }

        $mentor = TeamMember::query()->findOrFail($mentorId);

        if ($mentor->is($member) || ! $mentor->isQualifiedFor($request)) {
            throw WorkflowException::invalidMentor($mentor->name);
        }

        return [[
            'code' => 'UNDERQUALIFIED_ASSIGNMENT',
            'message' => "{$member->name} di bawah syarat kompetensi; pairing dengan {$mentor->name}. Alasan: {$overrideReason}",
        ]];
    }

    public function currentAbsence(TeamMember $member): ?CapacityEntry
    {
        $now = $this->clock->now();

        return $member->capacityEntries()
            ->overlapping($now, $now->addMinute())
            ->get()
            ->first(fn (CapacityEntry $entry) => $entry->isAbsence());
    }

    /** @return list<array{code: string, message: string}> */
    private function checkReady(ServiceRequest $request): array
    {
        if ($request->hasUnknownEstimate()) {
            throw WorkflowException::estimateRequired();
        }

        if ($request->required_skill_id === null) {
            throw WorkflowException::skillRequired();
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{code: string, message: string}>
     */
    private function checkAssignment(ServiceRequest $request, array $payload): array
    {
        $memberId = $payload['assignee_id'] ?? null;

        if ($memberId === null) {
            throw WorkflowException::assigneeRequired();
        }

        return $this->assertAssignable($request, TeamMember::query()->findOrFail($memberId), $payload);
    }

    /** @return list<array{code: string, message: string}> */
    private function checkStart(ServiceRequest $request): array
    {
        $this->assertNoOpenDependencies($request);

        $assignee = $request->assignee ?? throw WorkflowException::assigneeRequired();
        $inProgress = $assignee->assignedRequests()
            ->where('status', S::InProgress)
            ->whereKeyNot($request->id)
            ->count();

        if ($inProgress >= $assignee->wip_limit) {
            return [[
                'code' => 'WIP_LIMIT',
                'message' => "{$assignee->name} sudah mengerjakan {$inProgress} request (batas WIP {$assignee->wip_limit}). Context switching menurunkan throughput.",
            ]];
        }

        return [];
    }

    /** @return list<array{code: string, message: string}> */
    private function checkResumeRework(ServiceRequest $request): array
    {
        if ($request->assignee_id === null) {
            throw WorkflowException::assigneeRequired();
        }

        return [];
    }

    /**
     * Blokir wajib disertai alasan atau dependensi yang jelas agar waktu tunggu dapat dianalisis.
     *
     * @param  array<string, mixed>  $payload
     * @return list<array{code: string, message: string}>
     */
    private function checkBlock(?string $reason, array $payload): array
    {
        if (blank($reason) && empty($payload['dependency'])) {
            throw WorkflowException::reasonRequired('status terblokir (alasan atau dependensi)');
        }

        return [];
    }

    /** @return list<array{code: string, message: string}> */
    private function checkUnblock(ServiceRequest $request): array
    {
        $this->assertNoOpenDependencies($request);

        return [];
    }

    /** @return list<array{code: string, message: string}> */
    private function checkAcceptance(Actor $actor): array
    {
        if (! $actor->canAcceptDelivery()) {
            throw WorkflowException::acceptanceRole();
        }

        return [];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{code: string, message: string}>
     */
    private function checkReturn(?string $reason, array $payload): array
    {
        $this->requireReason($reason, 'pengembalian hasil pekerjaan');

        if ((float) ($payload['rework_estimate_hours'] ?? 0) <= 0) {
            throw WorkflowException::reworkEstimateRequired();
        }

        $cause = $payload['rework_cause'] ?? null;

        if (! $cause instanceof ReworkCause && ReworkCause::tryFrom((string) $cause) === null) {
            throw WorkflowException::reworkCauseRequired();
        }

        return [];
    }

    private function assertNoOpenDependencies(ServiceRequest $request): void
    {
        $open = $request->openDependencies()->count();

        if ($open > 0) {
            throw WorkflowException::openDependencies($open);
        }
    }

    /** @return list<array{code: string, message: string}> */
    private function requireReason(?string $reason, string $context): array
    {
        if (blank($reason)) {
            throw WorkflowException::reasonRequired($context);
        }

        return [];
    }
}
