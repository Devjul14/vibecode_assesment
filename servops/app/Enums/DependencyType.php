<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

enum DependencyType: string
{
    use EnumHelpers;

    case Internal = 'internal';
    case External = 'external';

    public function label(): string
    {
        return match ($this) {
            self::Internal => 'Internal',
            self::External => 'Eksternal',
        };
    }
}
