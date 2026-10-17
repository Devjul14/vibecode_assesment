<?php

namespace App\Enums;

use App\Enums\Concerns\EnumHelpers;

enum ImpactLevel: string
{
    use EnumHelpers;

    case Unknown = 'unknown';
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';
    case Critical = 'critical';

    public function label(): string
    {
        return match ($this) {
            self::Unknown => 'Belum Diketahui',
            self::Low => 'Rendah',
            self::Medium => 'Sedang',
            self::High => 'Tinggi',
            self::Critical => 'Kritis',
        };
    }

    /** Skor dasar faktor Impact. Unknown sengaja = medium (tidak diasumsikan rendah) + wajib review. */
    public function baseScore(): int
    {
        return match ($this) {
            self::Unknown, self::Medium => 50,
            self::Low => 25,
            self::High => 75,
            self::Critical => 100,
        };
    }
}
