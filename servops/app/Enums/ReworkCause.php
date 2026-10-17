<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

enum ReworkCause: string
{
    use EnumHelpers;

    case Quality = 'quality';
    case RequirementChange = 'requirement_change';
    case UnclearScope = 'unclear_scope';

    public function label(): string
    {
        return match ($this) {
            self::Quality => 'Kualitas Hasil',
            self::RequirementChange => 'Perubahan Kebutuhan',
            self::UnclearScope => 'Scope Tidak Jelas',
        };
    }
}
