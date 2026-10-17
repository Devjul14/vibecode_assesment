<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

enum RecommendationStatus: string
{
    use EnumHelpers;

    case Open = 'open';
    case Accepted = 'accepted';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Terbuka',
            self::Accepted => 'Diterima',
            self::Dismissed => 'Diabaikan',
        };
    }
}
