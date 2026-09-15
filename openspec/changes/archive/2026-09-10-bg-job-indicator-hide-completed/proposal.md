## Why

El widget flotante global `bg-job-indicator` (esquina inferior derecha) vuelve a mostrar tarjetas de escaneos de storages **ya completados** cuando el operador recarga la página entre 1h y 2h después de finalizar el job. El operador cierra las tarjetas (×) y aun así reaparecen, lo que genera ruido visual permanente y erosiona la confianza en el widget como señal de "hay algo activo AHORA".

Causa raíz doble:
1. `ApiTranscriptorController::processBatch` registra el runId en `transcription_batch:active_runs` como **string plano** sin `finishedAt`. `ScanAndSubmitCommand::handle` detecta que ya está listado y **no actualiza** la entrada con `finishedAt` al terminar. `TranscriptorBatchJobScanner` solo descarta entradas con `finishedAt` pasado más de `TERMINAL_TTL_SECONDS` (5 min), así que para estos jobs esa rama nunca corre y la entrada sobrevive hasta que expira la cache individual (2h).
2. El mapa `dismissed` del widget (`bg-job-indicator.blade.php` líneas 84-90) limpia entradas con más de 1h de antigüedad, así que un dismiss explícito del operador se "olvida" antes de que la cache subyacente expire.

## What Changes

- Reescribir la entrada de `transcription_batch:active_runs` cuando el comando artisan termina, **independientemente** de si el runId ya estaba listado (como string o como array). La entrada siempre termina con forma `{runId, finishedAt}`.
- Extender el TTL de "olvido" del mapa `dismissed` del widget de **1h a 24h**, y persistirlo con clave versionada para permitir migraciones futuras sin ambigüedad.
- Añadir un fix de hot-path en el scanner para que entradas-string legacy (cache caliente pre-deploy) se descarten después de `TERMINAL_TTL_SECONDS` contados desde su `updated_at` individual, no desde un `finishedAt` inexistente.

Sin cambios de UI visibles, sin breaking change, sin migración de BD.

## Capabilities

### New Capabilities
- `bg-job-indicator`: comportamiento observable del widget flotante global que muestra jobs en background activos por módulo (estado terminal, expiración de tarjetas, persistencia de dismissals del operador).

### Modified Capabilities
- *(ninguna — no hay spec previa que cubra este widget)*

## Impact

**Backend (PHP)**:
- `app/app/Console/Commands/ScanAndSubmitCommand.php` — bloque líneas 354-364 (cleanup al terminar).
- `app/app/Services/BgJobs/TranscriptorBatchJobScanner.php` — lectura de `finishedAt`/`updated_at` de la cache individual como fallback (líneas 47-61).

**Frontend (Blade/Alpine)**:
- `app/resources/views/components/bg-job-indicator.blade.php` — constantes `DISMISS_TTL_MS` y versión de `localStorage` key (líneas 84-90 y constructor del componente).

**Sin impacto en**: rutas, controllers HTTP (excepto la lógica interna de cleanup), base de datos, cola Redis, supervisord, cron `TranscriptionTickCommand` (no entra en `active_runs`).

## Non-goals

- No rediseñar el widget ni cambiar su contrato (`/bg-jobs/active`).
- No cambiar `TERMINAL_TTL_SECONDS` (5 min es suficiente para que el operador lea el resumen).
- No tocar la lógica de scan-and-submit en sí (queries, regulator, dispatch).
- No agregar endpoint nuevo para "borrar manualmente" un job del widget (la corrección del contrato basta).
- No migrar entradas-string legacy en bloque al deploy (el fallback de `updated_at` las drena progresivamente).
