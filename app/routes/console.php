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
//
// Tick unificado: corre cada 2 minutos. Phase 1 (discovery) escanea los archivos
// del día actual en storages habilitados; Phase 2 (regulator dispatch) encola
// hasta `target_redis_queue - current + runway` (clamped 10..200) jobs a Redis.
// Solo procesa archivos de `created_at >= today` (scope=current_day en .env).
// Documentado en openspec/changes/2026-07-22-transcription-operational-autotuning.
Schedule::command('transcription:tick')
    ->everyTwoMinutes()
    ->withoutOverlapping(150)
    ->appendOutputTo(storage_path('logs/transcription-tick.log'));

// Auto-ajuste del pool de workers systemd basado en # de medios equivalentes.
// Cada 5 min recalcula storages planos + subcarpetas de grouped_by_subfolder.
// systemctl enable/start/stop idempotente; ver TranscriptionTuneCommand.
Schedule::command('transcription:tune --apply')
    ->cron('*/5 * * * *')
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/transcription-tune.log'));

// Polling de resultados: cada 1 min recupera SRT de transcriptor para jobs queued/processing.
// Reenvia stuck (sin job_id > stale_after_minutes). Independiente del tick (fase 2).
Schedule::command('transcription:poll-results')->everyMinute()->withoutOverlapping();

// Limpieza de archivos temporales en /dev/shm (tmpfs) cada hora.
Schedule::command('transcription:cleanup-tmpfs')->hourly();

// transcription:apply-corrections queda SOLO manual (no se agenda).
// transcription-tick es el unico scheduled de descubrimiento+encolado.

