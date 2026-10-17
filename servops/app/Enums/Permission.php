<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

/**
 * Hak akses granular. Peran dipetakan ke daftar permission di ActorRole::permissions().
 */
enum Permission: string
{
    use EnumHelpers;

    case ViewDashboard = 'dashboard.view';
    case ViewTeam = 'team.view';
    case ViewRisk = 'risk.view';
    case ViewRecommendations = 'recommendations.view';
    case DecideRecommendations = 'recommendations.decide';
    case ViewAllRequests = 'requests.view_all';
    case CreateRequest = 'requests.create';
    case TriageRequest = 'requests.triage';
    case AssignRequest = 'requests.assign';
    case WorkAnyRequest = 'requests.work_any';
    case WorkOwnRequest = 'requests.work_own';
    case AcceptAnyDelivery = 'requests.accept_any';
    case AcceptOwnDelivery = 'requests.accept_own';
    case ManageDependencies = 'requests.dependencies';
    case ReviseEstimate = 'requests.estimate';
    case OverridePriority = 'priority.override';
    case ManageCapacity = 'capacity.manage';
    case ManageOwnCapacity = 'capacity.manage_own';
    case RunSimulation = 'simulation.run';

    public function label(): string
    {
        return match ($this) {
            self::ViewDashboard => 'Lihat dashboard',
            self::ViewTeam => 'Lihat tim & kapasitas',
            self::ViewRisk => 'Lihat risiko SLA',
            self::ViewRecommendations => 'Lihat rekomendasi',
            self::DecideRecommendations => 'Terima / abaikan rekomendasi',
            self::ViewAllRequests => 'Lihat semua request',
            self::CreateRequest => 'Buat request',
            self::TriageRequest => 'Triage, klarifikasi, batalkan',
            self::AssignRequest => 'Tugaskan / pindahkan',
            self::WorkAnyRequest => 'Kerjakan request siapa pun',
            self::WorkOwnRequest => 'Kerjakan request sendiri',
            self::AcceptAnyDelivery => 'Terima / kembalikan hasil (semua)',
            self::AcceptOwnDelivery => 'Terima / kembalikan hasil (departemen sendiri)',
            self::ManageDependencies => 'Kelola dependensi',
            self::ReviseEstimate => 'Revisi estimasi',
            self::OverridePriority => 'Override prioritas',
            self::ManageCapacity => 'Kelola kapasitas semua anggota',
            self::ManageOwnCapacity => 'Catat ketidakhadiran sendiri',
            self::RunSimulation => 'Jalankan simulasi',
        };
    }
}
