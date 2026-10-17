<?php

namespace Tests\Feature;

use App\Livewire\Dashboard;
use App\Livewire\Recommendations\Index as RecommendationIndex;
use App\Livewire\Requests\Index as RequestIndex;
use App\Livewire\Risk\Index as RiskIndex;
use App\Models\Recommendation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\UsesSeededScenario;
use Tests\TestCase;

class UiPagesTest extends TestCase
{
    use RefreshDatabase, UsesSeededScenario;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedScenario();
        $this->loginAs('rudi');
        $this->withoutVite();
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function pages(): array
    {
        return [
            'dashboard' => ['/dashboard', 'Dashboard keputusan'],
            'requests' => ['/requests', 'Gangguan proses operasional'],
            'create' => ['/requests/create', 'Request baru'],
            'detail' => ['/requests/SR-102', 'Mengapa prioritas ini?'],
            'closed detail' => ['/requests/SR-001', 'Riwayat'],
            'team' => ['/team', 'Matriks kompetensi'],
            'risk' => ['/risk', 'Mulai paling lambat'],
            'recommendations' => ['/recommendations', 'Tugaskan SR-102 ke Budi'],
        ];
    }

    #[DataProvider('pages')]
    public function test_page_renders(string $uri, string $expected): void
    {
        $this->get($uri)->assertOk()->assertSee($expected);
    }

    public function test_dashboard_explains_why_and_what_to_do(): void
    {
        Livewire::test(Dashboard::class)
            ->assertSee('Mengapa pekerjaan berisiko')
            ->assertSee('Skill langka')
            ->assertSee('Tim Finance')
            ->assertSee('Rata² mentah');
    }

    public function test_dashboard_accepts_recommendation(): void
    {
        $id = Recommendation::query()->open()->where('title', 'Tugaskan SR-102 ke Budi')->value('id');

        Livewire::test(Dashboard::class)
            ->call('acceptRecommendation', $id)
            ->assertSet('notice.type', 'success');

        $this->assertSame('Budi', $this->sr('SR-102')->assignee->name);
    }

    public function test_request_list_filters(): void
    {
        Livewire::test(RequestIndex::class)
            ->set('assignee', 'none')
            ->assertSee('SR-102')
            ->assertDontSee('SR-095')
            ->set('assignee', '')
            ->set('reviewOnly', true)
            ->assertSee('SR-107')
            ->assertDontSee('SR-101')
            ->set('reviewOnly', false)
            ->set('scope', 'done')
            ->assertSee('SR-001')
            ->assertDontSee('SR-102');
    }

    public function test_risk_monitor_filters_by_level_and_shows_live_wait_time(): void
    {
        Livewire::test(RiskIndex::class)
            ->assertSee('5.5 j')
            ->set('level', 'on_track')
            ->assertSee('SR-101')
            ->assertDontSee('SR-105');
    }

    public function test_recommendation_dismiss_with_note(): void
    {
        $id = Recommendation::query()->open()->where('title', 'Triage dampak SR-107')->value('id');

        Livewire::test(RecommendationIndex::class)
            ->set("notes.{$id}", 'Sudah ditriage lewat telepon')
            ->call('dismiss', $id)
            ->assertSee('Sudah ditriage lewat telepon');
    }
}
