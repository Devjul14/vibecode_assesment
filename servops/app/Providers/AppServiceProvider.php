<?php

namespace App\Providers;

use App\Enums\Permission;
use App\Models\User;
use App\Services\Assignment\AssignmentRecommender;
use App\Services\Calendar\Clock;
use App\Services\Calendar\WorkingCalendar;
use App\Services\Capacity\CapacityService;
use App\Services\Estimation\EstimateAdvisor;
use App\Services\Risk\SlaRiskEngine;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Clock::class);
        $this->app->singleton(WorkingCalendar::class, fn (): WorkingCalendar => new WorkingCalendar(config('servops.calendar')));

        // Engine dengan memo per-perhitungan; singleton agar MetricsRecomputer::flushCaches() mengenai instance yang sama.
        $this->app->singleton(CapacityService::class);
        $this->app->singleton(EstimateAdvisor::class);
        $this->app->singleton(AssignmentRecommender::class);
        $this->app->singleton(SlaRiskEngine::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, fn (User $user): bool => $user->hasPermission($permission));
        }
    }
}
