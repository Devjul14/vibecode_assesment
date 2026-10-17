<?php

namespace App\Livewire\Recommendations;

use App\Enums\RecommendationStatus;
use App\Livewire\Concerns\InteractsWithServOps;
use App\Models\Recommendation;
use App\Services\Recommendation\RecommendationService;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Rekomendasi terbuka (keputusan manusia) dan riwayat keputusan.
 */
#[Title('Rekomendasi')]
class Index extends Component
{
    use InteractsWithServOps;

    /** @var array<int, string> */
    public array $notes = [];

    public function accept(int $id, RecommendationService $service): void
    {
        $recommendation = Recommendation::query()->findOrFail($id);
        $this->attempt(function () use ($service, $recommendation) {
            Gate::authorize('recommendations.decide');

            return $service->accept($recommendation, $this->actor());
        }, "Diterima: {$recommendation->title}");
    }

    public function dismiss(int $id, RecommendationService $service): void
    {
        $recommendation = Recommendation::query()->findOrFail($id);
        $this->attempt(function () use ($service, $recommendation, $id) {
            Gate::authorize('recommendations.decide');

            return $service->dismiss($recommendation, $this->actor(), $this->notes[$id] ?? null);
        }, "Diabaikan: {$recommendation->title}");
    }

    public function render(): View
    {
        return view('livewire.recommendations.index', [
            'open' => Recommendation::query()->open()->with(['serviceRequest', 'member'])->orderByDesc('severity')->orderBy('id')->get(),
            'decided' => Recommendation::query()->where('status', '!=', RecommendationStatus::Open)->with('serviceRequest')->latest('decided_at')->limit(30)->get(),
        ]);
    }
}
