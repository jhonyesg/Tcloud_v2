<?php

use App\Services\SessionService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// withoutOverlapping(30): el TTL por defecto es de 1440 minutos. Un SIGKILL
// durante un cuelgue de NFS (donde el proceso queda bloqueado en IO
// ininterrumpible) dejaba el lock muerto y paraba TODOS los syncs 24 horas sin
// avisar. 30 min deja margen holgado sobre los ~4 min de ejecucion real.
Schedule::command('storage:sync --all')->everyFifteenMinutes()->withoutOverlapping(30);

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
//
// TTL explicito: withoutOverlapping() sin argumento usa 1440 minutos. El mutex
// solo lo libera el proceso que lo tomo, asi que un SIGKILL a mitad de ciclo
// dejaba el polling parado 24h en silencio — y el polling es el UNICO camino
// de retorno de resultados (no hay webhook entrante). 10 min cubre de sobra un
// ciclo normal.
Schedule::command('transcription:poll-results')
    ->everyMinute()
    ->withoutOverlapping(10);

// Centinela de flujo: cada hora comprueba que sigan naciendo transcripciones.
//
// Es la pieza que faltó el 2026-08-18: el pipeline estuvo 44 horas parado (el
// pivote user_storages.transcription_enabled quedó vacío tras una migración) y
// ninguna pieza avisó, porque cada una reportaba su propio estado como normal.
// Este no mira componentes, mira el resultado. Sin
// TRANSCRIPTOR_HEALTH_ALERT_EMAIL solo escribe WARNING en laravel.log.
Schedule::command('transcription:health-check')
    ->hourly()
    ->withoutOverlapping(30);

// Limpieza de archivos temporales en /dev/shm (tmpfs) cada hora.
Schedule::command('transcription:cleanup-tmpfs')->hourly();

// Limpieza defensiva de WAVs huérfanos (>30 min, sin fd abierto) en /dev/shm.
// Red de seguridad tras el fix del fd leak (2026-08-12): no debería encontrar
// nada que limpiar, pero si un crash/kill -9 deja archivos sin cerrar, los
// libera sin afectar jobs en curso.
Schedule::command('transcription:cleanup-orphan-wav')
    ->everyFifteenMinutes()
    ->withoutOverlapping(60);

// Centinela de /dev/shm: cada 10 min verifica uso y emite WARNING si supera
// el umbral (default 80%). Cache del estado para el endpoint shm-status.
Schedule::command('transcription:check-shm-health')
    ->everyTenMinutes()
    ->withoutOverlapping(30);

// Limpieza diaria del log de undo de bulk actions (corrections-bulk-moderation).
// Borra entries con expires_at < now() - retention (default 7d).
Schedule::command('corrections:cleanup-undo-log')->daily()->at('04:00')->withoutOverlapping(60);

// Reporte semanal de triage (cambios/2026-08-18-corrections-coherence-learn-fix-and-pending-triage).
// Solo dry-run: el admin revisa el log y decide si aplicar desde la UI.
Schedule::command('corrections:triage-pending --dry-run')
    ->weekly()
    ->saturdays()
    ->at('04:30')
    ->withoutOverlapping(60)
    ->appendOutputTo(storage_path('logs/corrections-triage.log'));

// === Auto-cycle de detección y sugerencias EN→ES (cambios 2026-08-11-english-residual-segment-detector) ===
//
// Cada 4 horas ejecuta el ciclo:
//   1) findFlaggedTranscriptions (umbral 0.5) sobre las últimas 4h
//   2) extrae bigramas (function_en, ES_noun) recurrentes
//   3) filtra contra pending/approved existentes y la heurística de sustantivos
//   4) inserta candidatos como PENDING (NO approved) con source='auto-cycle-YYYY-MM-DD'
//
// El admin revisa las pendientes en /ia/correcciones y las aprueba/rechaza.
// Genera max 5 reglas por corrida para mantener ruido bajo. Threshold alto
// (0.7) marca solo segmentos con mezcla EN/ES severa. min-freq=15 evita
// rules espurias de un solo segmento.
Schedule::command('corrections:cycle-suggestions --hours=4 --threshold=0.7 --min-freq=15 --max-rules=5')
    ->everyFourHours()
    ->withoutOverlapping(120)
    ->appendOutputTo(storage_path('logs/corrections-cycle.log'));

// === Detector de transcripciones con inglés residual (mismo ritmo) ===
//
// Marca como needs_review las transcripciones done de las últimas 4h
// con segmentos que superen threshold 0.5 (mezcla EN/ES severa).
// Idempotente: no pisa status humano preexistente (correct/ignored).
Schedule::command('corrections:detect-english-residual --hours=4 --threshold=0.5 --apply')
    ->everyFourHours()
    ->withoutOverlapping(120)
    ->appendOutputTo(storage_path('logs/corrections-detect.log'));

// === Minería EN->ES: DESPROGRAMADA el 2026-08-11 ===
//
// Aquí corrían dos tareas que alimentaban el diccionario con traducciones
// inglés->español:
//
//   Schedule::command('corrections:mine-en-es --days=14 --min-freq=5')->weekly()...
//   Schedule::command('corrections:ai-suggest --days=1 --sample=200')->everyTwoHours()...
//
// El encargo original (2026-08-01) fue "hay mucho texto en inglés y necesito
// que eso se corrija", y con 12 corridas/día auto-aprobando en risk_level='low'
// el resultado fueron 2.465 reglas de traducción palabra por palabra con 205.000
// aplicaciones: the->la (84.011), in->en (41.104), and->y (38.281), are->están.
//
// Un motor de find/replace no puede traducir: no tiene contexto ni concordancia.
// Lo que producía era espanglish PEOR que el original —
//   "The cooperativas are dotadas of two motors."
//     -> "la cooperativas están dotadas of two motors."
// y además degradaba español correcto ("al diseño" -> "al deño").
//
// La causa real es que el ASR devuelve inglés (e italiano) en audio español;
// eso se arregla en el transcriptor, no traduciendo a posteriori. Ver
// EnEsRuleClassifier y `corrections:quarantine-en-es`.
//
// Los comandos siguen existiendo para uso manual y ahora pasan por el guardrail
// (rechazan pares EN->ES y entran como pending, sin auto-aprobar), pero no se
// agendan: sin nada que aportar, solo gastarían tokens de LLM cada 2 horas.

// transcription:apply-corrections queda SOLO manual (no se agenda).
// transcription-tick es el unico scheduled de descubrimiento+encolado.

