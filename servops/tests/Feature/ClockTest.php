<?php

namespace Tests\Feature;

use App\Services\Calendar\Clock;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class ClockTest extends TestCase
{
    public function test_frozen_time_is_returned_and_can_advance(): void
    {
        $clock = app(Clock::class);
        $clock->freeze(CarbonImmutable::parse('2026-10-19 09:00', 'Asia/Jakarta'));

        $this->assertTrue($clock->isSimulated());
        $this->assertSame('2026-10-19 09:00', $clock->now()->format('Y-m-d H:i'));
        $this->assertSame('2026-10-19 15:30', $clock->advance(6.5)->format('Y-m-d H:i'));
    }

    public function test_release_returns_to_real_time(): void
    {
        $clock = app(Clock::class);
        $clock->freeze(CarbonImmutable::parse('2020-01-01 09:00'));
        $clock->release();

        $this->assertFalse($clock->isSimulated());
        $this->assertTrue($clock->now()->isSameDay(CarbonImmutable::now()));
    }

    public function test_config_value_is_used_when_nothing_is_frozen(): void
    {
        config(['servops.now' => '2026-11-02T10:00:00+07:00']);

        $this->assertSame('2026-11-02 10:00', app(Clock::class)->now()->format('Y-m-d H:i'));
    }
}
