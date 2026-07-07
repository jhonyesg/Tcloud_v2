<?php

use App\Services\SessionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('storage:sync --all')->everyFifteenMinutes()->withoutOverlapping();

Schedule::call(function () {
    $service = app(SessionService::class);
    $service->cleanOrphans();
    $service->cleanExpired();
})->everyThirtyMinutes()->name('sessions:cleanup')->withoutOverlapping();

// Limpieza de logs de acceso a shares más antiguos de 90 días (corre 1 vez/semana)
Schedule::command('shares:cleanup-logs --days=90')->weekly()->sundays()->at('03:00');

// Limpieza de logs de correo más antiguos de 90 días (corre 1 vez/semana)
Schedule::command('correo:cleanup-logs --days=90')->weekly()->sundays()->at('03:15');

// Corrección de cuotas personales — detecta y corrige drift (corre 1 vez/semana)
Schedule::command('files:recalc-personal-quota')->weekly()->sundays()->at('03:30');

// Modulo IA — transcripción
// Escaneo de archivos nuevos para encolar transcripción (cada 2 min)
Schedule::command('transcription:scan-new')->everyTwoMinutes()->withoutOverlapping();
// Polling de respaldo para webhooks perdidos (cada 5 min)
Schedule::command('transcription:scan-stale')->everyFiveMinutes()->withoutOverlapping();
// Limpieza de archivos temporales en tmpfs (/dev/shm) cada hora
Schedule::command('transcription:cleanup-tmpfs')->hourly();
// transcription:apply-corrections queda solo manual (no se agenda)
