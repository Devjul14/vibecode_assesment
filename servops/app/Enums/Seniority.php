<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

enum Seniority: string
{
    use EnumHelpers;

    case Junior = 'junior';
    case Mid = 'mid';
    case Senior = 'senior';

    public function label(): string
    {
        return match ($this) {
            self::Junior => 'Junior',
            self::Mid => 'Mid',
            self::Senior => 'Senior',
        };
    }
}
