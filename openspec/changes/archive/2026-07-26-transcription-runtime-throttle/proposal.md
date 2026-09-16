# Change: Regulación en caliente del pipeline de transcripción

## Why

El envío al transcriptor no es el problema: sucede **de golpe**. `transcription:tick` escanea y dispara el lote entero de una vez, y ningún número es ajustable sin editar `.env` y desplegar. Verificado hoy:

1. **El freno del regulador es código muerto.** `TranscriptionTickCommand.php:80` aplica `max($min, ...)` antes de evaluar el freno; con `min_batch=10` el `if ($batch <= 0)` de la línea 82 jamás corre. Con la cola en 300 sigue inyectando 10 jobs cada 2 min. Contradice `transcription-orchestrator-runtime` §3.
2. **Trabajo duplicado.** `config/queue.php:26` `retry_after=90` vs `ConvertAndTranscribeJob.php:28` `$timeout=600`: todo job de más de 90 s se re-encola mientras el original sigue en ffmpeg.
3. **21 workers en vez de 11.** Los 10 `tcloud-transcription-worker@*` están activos y `TranscriptionTuneCommand` no los ve. Viola §7. Alimentan una API con 2 workers GPU.
4. **Multiplicador sin regular.** `ScanAndSubmitCommand.php:178` calcula `scan_batch × count($storages)` = 3100 jobs, saltándose el regulador. Es la ruta del botón "Escanear storages".
5. **Ráfaga del navegador.** `index.blade.php:1756` usa `Promise.allSettled` sin tope contra `POST /ia/api-transcriptor/transcribe/{fileId}`, que corre ffmpeg + POST síncronos en php-fpm.

Evidencia: 2026-07-24 11:35:32–36, ~15 `ffmpeg falló` en 4 segundos con stderr truncado a offsets distintos. Cero HTTP 429: el que se ahoga es este servidor.

## What Changes

- **Fase 1**: déficit antes del clamp; `retry_after` 90→900; tope propio en `ScanAndSubmitCommand`; pool acotado en el JS; `reconcileForbiddenPools()` en el tuner.
- **Fase 2**: `App\Services\Ia\TranscriptorSettings` sobre `system_settings` (prefijo `transcriptor.*`), esquema único que alimenta accessor, validación y formulario. Comando `transcription:config`.
- **Fase 3**: `TranscriptorSettingsController` con `GET/POST /ia/api-transcriptor/settings` y `.../settings/reset` en el grupo `['auth','admin']->prefix('ia')`; pestaña "Configuración" con tarjeta de la tarea programada, estado en vivo, `dispatch_stagger_ms` y freno `dispatch_paused`.
- **Fase 4**: las `private const` del tuner pasan a settings, más `worker_override`.
- **Fase 5**: semáforo `Redis::funnel` como job middleware (`inflight_max`), `ShouldBeUnique`, retry HTTP y streaming del opus en `TranscriptorApiClient`.

## Non-goals

- Reescribir `DiskScannerService`. Solo se le inyectan settings.
- Horizon o supervisord. El pool sigue siendo systemd.
- Reactivar webhooks (§9 lo prohíbe); `callback_host` se mapea solo para display.
- Visor de transcripciones, correcciones y Avisos Inteligentes.
- Editar units systemd desde la app.

## Impact

- **Specs**: `transcription-orchestrator-runtime` (§3, §6, §7, §9) y `transcription-disk-scanner` (límite de lote), ambos modificados.
- **Migrations**: **ninguna**. Reutiliza `system_settings` (`2026_05_13_100001_*`) con prefijo de clave.
- **Rutas**: 4 nuevas bajo `/ia/api-transcriptor/settings*`; `throttle` en `transcribe/{fileId}`.
- **Código** (inventario completo en `design.md`): 2 config, 2 routes, `AppServiceProvider`, 5 comandos, 6 servicios `Ia`, `ConvertAndTranscribeJob`, `ApiTranscriptorController`, `ia/api-transcriptor/index.blade.php`; 4 clases nuevas y 2 tests.
- **Compatibilidad**: `config('transcriptor.*')` pasa de fuente de verdad a capa de fallback. Rollback = `DELETE FROM system_settings WHERE key LIKE 'transcriptor.%'`.
- **Operador**: tras la Fase 1, `systemctl restart 'tcloud-transcription-batch-*'` y `php artisan transcription:tune --apply`.
