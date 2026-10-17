<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

enum RecommendationType: string
{
    use EnumHelpers;

    case Reassign = 'reassign';
    case Rebalance = 'rebalance';
    case EscalateDependency = 'escalate_dependency';
    case ClarifyScope = 'clarify_scope';
    case TriageImpact = 'triage_impact';
    case StartEarly = 'start_early';
    case Pairing = 'pairing';
    case NegotiateDeadline = 'negotiate_deadline';
    case SplitScope = 'split_scope';
    case AddCapacity = 'add_capacity';
    case CrossTrain = 'cross_train';

    public function label(): string
    {
        return match ($this) {
            self::Reassign => 'Tugaskan / Pindahkan',
            self::Rebalance => 'Seimbangkan Beban',
            self::EscalateDependency => 'Eskalasi Dependensi',
            self::ClarifyScope => 'Klarifikasi Scope',
            self::TriageImpact => 'Triage Dampak',
            self::StartEarly => 'Mulai Lebih Awal',
            self::Pairing => 'Pairing / Mentoring',
            self::NegotiateDeadline => 'Negosiasi Tenggat',
            self::SplitScope => 'Pecah Scope',
            self::AddCapacity => 'Tambah Kapasitas',
            self::CrossTrain => 'Cross-training',
        };
    }
}
