<?php

use App\Services\SessionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('storage:sync --all')->everyFifteenMinutes();

Schedule::call(function () {
    $service = app(SessionService::class);
    $service->cleanOrphans();
    $service->cleanExpired();
})->everyThirtyMinutes()->name('sessions:cleanup')->withoutOverlapping();
