<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Waktu terus berjalan: slack menyusut dan risiko berubah meski tidak ada perubahan data.
Schedule::command('servops:recompute')->everyFifteenMinutes()->withoutOverlapping();
