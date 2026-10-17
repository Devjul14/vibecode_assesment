<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

enum CapacityEntryType: string
{
    use EnumHelpers;

    case Leave = 'leave';
    case Sick = 'sick';
    case Meeting = 'meeting';
    case Training = 'training';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Leave => 'Cuti',
            self::Sick => 'Sakit',
            self::Meeting => 'Rapat',
            self::Training => 'Pelatihan',
            self::Other => 'Lainnya',
        };
    }
}
