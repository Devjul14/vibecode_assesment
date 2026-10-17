<?php

namespace App\Policies;

use App\Enums\Permission as P;
use App\Enums\RequestStatus as S;
use App\Models\ServiceRequest;
use App\Models\User;

/**
 * Otorisasi per request: permission peran + kepemilikan (assignee / departemen pemohon).
 */
class ServiceRequestPolicy
{
    public function view(User $user, ServiceRequest $request): bool
    {
        return $user->hasPermission(P::ViewAllRequests) || $user->isRequesterOf($request);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission(P::CreateRequest);
    }

    /**
     * Transisi status dipetakan ke kelompok permission:
     * triage/klarifikasi/batal → triage; penugasan → assign; pengerjaan → work; acceptance → accept.
     */
    public function transition(User $user, ServiceRequest $request, S $to): bool
    {
        $from = $request->status;

        return match (true) {
            $to === S::Assigned, $from === S::Assigned && $to === S::Ready => $user->hasPermission(P::AssignRequest),
            $to === S::Accepted, $to === S::Returned => $user->hasPermission(P::AcceptAnyDelivery)
                || ($user->hasPermission(P::AcceptOwnDelivery) && $user->isRequesterOf($request)),
            $to === S::InProgress, $to === S::Blocked, $to === S::Delivered => $this->work($user, $request),
            $from === S::InProgress && $to === S::Triage => $user->hasPermission(P::TriageRequest) || $this->work($user, $request),
            default => $user->hasPermission(P::TriageRequest),
        };
    }

    public function assign(User $user, ServiceRequest $request): bool
    {
        return $user->hasPermission(P::AssignRequest);
    }

    public function reviseEstimate(User $user, ServiceRequest $request): bool
    {
        return $user->hasPermission(P::ReviseEstimate) || $this->work($user, $request);
    }

    public function manageDependencies(User $user, ServiceRequest $request): bool
    {
        return $user->hasPermission(P::ManageDependencies) || $this->work($user, $request);
    }

    public function assessImpact(User $user, ServiceRequest $request): bool
    {
        return $user->hasPermission(P::TriageRequest);
    }

    public function override(User $user, ServiceRequest $request): bool
    {
        return $user->hasPermission(P::OverridePriority);
    }

    private function work(User $user, ServiceRequest $request): bool
    {
        return $user->hasPermission(P::WorkAnyRequest)
            || ($user->hasPermission(P::WorkOwnRequest) && $user->isAssigneeOf($request));
    }
}
