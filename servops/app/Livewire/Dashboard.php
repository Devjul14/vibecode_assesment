<?php

namespace App\Livewire;

use App\Livewire\Concerns\InteractsWithServOps;
use App\Models\Recommendation;
use App\Services\Capacity\CapacityService;
use App\Services\Metrics\DashboardMetrics;
use App\Services\Recommendation\RecommendationService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Decision support: bukan hanya berapa banyak pekerjaan, tetapi mengapa berisiko,
 * di mana bottleneck, dan tindakan apa yang layak diambil.
 */
#[Title('Dashboard')]
class Dashboard extends Component
{
    use InteractsWithServOps;

    public function acceptRecommendation(int $id, RecommendationService $service): void
    {
        $recommendation = Recommendation::query()->findOrFail($id);
        $this->attempt(function () use ($service, $recommendation) {
            Gate::authorize('recommendations.decide');

            return $service->accept($recommendation, $this->actor());
        }, "Rekomendasi diterima: {$recommendation->title}");
    }

    public function render(DashboardMetrics $metrics, CapacityService $capacity): View
    {
        $capacity->flush();

        return view('livewire.dashboard', [
            'kpis' => $metrics->kpis(),
            'attention' => $metrics->needsAttention(),
            'causes' => $metrics->riskCauses(),
            'blockers' => $metrics->blockers(),
            'recommendations' => $metrics->topRecommendations(),
            'team' => $capacity->teamSnapshot(),
            'skills' => $capacity->skillBalance(),
            'accuracy' => $metrics->estimationAccuracy(),
            'riskDistribution' => $metrics->riskDistribution(),
        ]);
    }
}
