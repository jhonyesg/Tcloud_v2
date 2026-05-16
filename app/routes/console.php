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

// Limpieza de logs de acceso a shares más antiguos de 90 días (corre 1 vez/semana)
Schedule::command('shares:cleanup-logs --days=90')->weekly()->sundays()->at('03:00');

// Corrección de cuotas personales — detecta y corrige drift (corre 1 vez/semana)
Schedule::command('files:recalc-personal-quota')->weekly()->sundays()->at('03:30');
