<?php

use App\Livewire\Auth\Login;
use App\Livewire\Dashboard;
use App\Livewire\Recommendations\Index as RecommendationIndex;
use App\Livewire\Requests\Create as RequestCreate;
use App\Livewire\Requests\Index as RequestIndex;
use App\Livewire\Requests\Show as RequestShow;
use App\Livewire\Risk\Index as RiskIndex;
use App\Livewire\Simulation\Index as SimulationIndex;
use App\Livewire\Team\Index as TeamIndex;
use App\Models\ServiceRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

Route::livewire('/login', Login::class)->middleware('guest')->name('login');

Route::middleware('auth')->group(function () {
    Route::get('/', fn () => redirect()->route(Gate::allows('dashboard.view') ? 'dashboard' : 'requests.index'))->name('home');

    Route::livewire('/dashboard', Dashboard::class)->middleware('can:dashboard.view')->name('dashboard');
    Route::livewire('/requests', RequestIndex::class)->name('requests.index');
    Route::livewire('/requests/create', RequestCreate::class)->middleware('can:create,'.ServiceRequest::class)->name('requests.create');
    Route::livewire('/requests/{serviceRequest}', RequestShow::class)->middleware('can:view,serviceRequest')->name('requests.show');
    Route::livewire('/team', TeamIndex::class)->middleware('can:team.view')->name('team.index');
    Route::livewire('/risk', RiskIndex::class)->middleware('can:risk.view')->name('risk.index');
    Route::livewire('/recommendations', RecommendationIndex::class)->middleware('can:recommendations.view')->name('recommendations.index');
    Route::livewire('/simulation', SimulationIndex::class)->middleware('can:simulation.run')->name('simulation.index');

    Route::post('/logout', function (Request $request) {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    })->name('logout');
});
