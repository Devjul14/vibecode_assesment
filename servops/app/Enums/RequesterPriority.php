<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

enum RequesterPriority: string
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

    public function score(): int
    {
        return match ($this) {
            self::Low => 30,
            self::Medium => 60,
            self::High => 100,
        };
    }
}
