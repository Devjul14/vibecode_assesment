<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

enum EstimateConfidence: string
{
    use EnumHelpers;

    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return match ($this) {
            self::Low => 'Rendah',
            self::Medium => 'Sedang',
            self::High => 'Tinggi',
        };
    }
}
