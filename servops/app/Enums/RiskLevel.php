<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

enum RiskLevel: string
{
    use EnumHelpers;

    case OnTrack = 'on_track';
    case AtRisk = 'at_risk';
    case WillBreach = 'will_breach';
    case Breached = 'breached';

    public function label(): string
    {
        return match ($this) {
            self::OnTrack => 'On Track',
            self::AtRisk => 'Berisiko',
            self::WillBreach => 'Akan Terlambat',
            self::Breached => 'Melewati Tenggat',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::OnTrack => 'emerald',
            self::AtRisk => 'amber',
            self::WillBreach => 'rose',
            self::Breached => 'zinc',
        };
    }

    public function severity(): int
    {
        return match ($this) {
            self::OnTrack => 0,
            self::AtRisk => 1,
            self::WillBreach => 2,
            self::Breached => 3,
        };
    }
}
