<?php

namespace Tests\Feature;

use App\Enums\CapacityEntryType;
use App\Models\CapacityEntry;
use App\Models\Recommendation;
use App\Services\Recommendation\ImpactPreviewService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\UsesSeededScenario;
use Tests\TestCase;

class ImpactPreviewTest extends TestCase
{
    use RefreshDatabase, UsesSeededScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedScenario();
    }

    public function test_preview_reports_changes_without_persisting_them(): void
    {
        $recommendations = Recommendation::query()->count();

        $rows = app(ImpactPreviewService::class)->preview(fn () => CapacityEntry::create([
            'team_member_id' => $this->teamMember('Citra')->id,
            'type' => CapacityEntryType::Sick,
            'starts_at' => '2026-10-19 00:00',
            'ends_at' => '2026-10-23 23:59',
        ]));

        $codes = array_column($rows, 'code');
        $this->assertContains('SR-097', $codes);
        $this->assertTrue(collect($rows)->firstWhere('code', 'SR-097')['worse']);

        $this->assertSame(0, CapacityEntry::query()->where('type', CapacityEntryType::Sick)->count());
        $this->assertSame($recommendations, Recommendation::query()->count());
        $this->assertSame('on_track', $this->sr('SR-097')->risk_level->value);
    }
}
