<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

enum TimeLogKind: string
{
    use EnumHelpers;

    case Work = 'work';
    case Rework = 'rework';

    public function label(): string
    {
        return match ($this) {
            self::Work => 'Pengerjaan',
            self::Rework => 'Rework',
        };
    }
}
