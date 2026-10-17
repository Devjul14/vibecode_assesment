<?php

namespace App\Services\Calendar;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Cache;

/**
 * Sumber tunggal "waktu sekarang" untuk seluruh engine.
 *
 * Urutan: waktu simulasi yang dibekukan (cache) → SERVOPS_NOW (config) → waktu nyata.
 */
class Clock
{
    public const CACHE_KEY = 'servops.sim_now';

    public function now(): CarbonImmutable
    {
        $frozen = Cache::get(self::CACHE_KEY) ?? config('servops.now');

        return $frozen
            ? CarbonImmutable::parse($frozen)->setTimezone(config('app.timezone'))
            : CarbonImmutable::now();
    }

    public function freeze(CarbonInterface $at): CarbonImmutable
    {
        Cache::forever(self::CACHE_KEY, $at->toIso8601String());

        return $this->now();
    }

    /** Geser waktu simulasi maju sejumlah jam kalender (dari waktu sekarang menurut Clock). */
    public function advance(float $hours): CarbonImmutable
    {
        return $this->freeze($this->now()->addSeconds((int) round($hours * 3600)));
    }

    public function release(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** Nilai mentah waktu beku (untuk dipulihkan setelah preview). */
    public function state(): ?string
    {
        return Cache::get(self::CACHE_KEY);
    }

    public function restore(?string $state): void
    {
        $state === null ? Cache::forget(self::CACHE_KEY) : Cache::forever(self::CACHE_KEY, $state);
    }

    public function isSimulated(): bool
    {
        return Cache::has(self::CACHE_KEY) || filled(config('servops.now'));
    }
}
