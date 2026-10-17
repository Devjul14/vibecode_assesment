<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

enum ActorRole: string
{
    use EnumHelpers;

    case Manager = 'manager';
    case TeamLead = 'team_lead';
    case Member = 'member';
    case Requester = 'requester';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Manager => 'Manager',
            self::TeamLead => 'Team Lead',
            self::Member => 'Anggota Tim',
            self::Requester => 'Pemohon',
            self::System => 'Sistem',
        };
    }

    /**
     * Matriks RBAC: permission yang dimiliki setiap peran.
     *
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return match ($this) {
            self::Manager, self::System => Permission::cases(),
            self::TeamLead => [
                Permission::ViewDashboard, Permission::ViewTeam, Permission::ViewRisk,
                Permission::ViewRecommendations, Permission::DecideRecommendations,
                Permission::ViewAllRequests, Permission::CreateRequest, Permission::TriageRequest,
                Permission::AssignRequest, Permission::WorkAnyRequest, Permission::ManageDependencies,
                Permission::ReviseEstimate, Permission::ManageCapacity, Permission::RunSimulation,
            ],
            self::Member => [
                Permission::ViewDashboard, Permission::ViewTeam, Permission::ViewRisk,
                Permission::ViewRecommendations, Permission::ViewAllRequests, Permission::CreateRequest,
                Permission::WorkOwnRequest, Permission::ManageOwnCapacity,
            ],
            self::Requester => [
                Permission::CreateRequest, Permission::AcceptOwnDelivery,
            ],
        };
    }

    public function can(Permission $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }
}
