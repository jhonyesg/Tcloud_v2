# Design — Sub-ventana de configuración del escaneo de menciones

## Context

Ver proposal.md. Restricciones y piezas existentes:

- `KeywordMatcher::run(Transcription): int` (mis-avisos-menciones Fase 1) ya es **idempotente**: si la transcripción ya tiene hits en `segment_keyword_hits`, retorna 0 sin reprocesar. UNIQUE triple + insertOrIgnore garantizan no-duplicación. El escaneo es universal: un solo pase de segmentos contra el conjunto DISTINCT de keywords con acceso.
- `TranscriptionProcessor` llama al matcher solo al terminar una transcripción con `generate_alerts=true` (columna default true). `reprocessCorrected()` omite el trigger.
- `avisos:deliver-alerts` corre cada minuto y SOLO entrega (cadencia, techo, rate limiter). No se toca.
- Patrón de settings ya probado: `SystemSetting::get/set` (ej. `sessions_cleanup_interval_minutes` en `routes/console.php`: cron con tick fijo + decisión interna; comentado allí que la expresión cron no puede componerse dinámicamente porque Laravel la cachea al boot).
- Módulo admin: `AvisosInteligentesController` (habilitación, límites, gestores) + `ia/avisos-inteligentes/index.blade.php` (182 líneas, tabla de clientes con Alpine).

## Goals / Non-Goals

Goals: que las menciones se generen también fuera del pipeline (backfill de lo terminado sin hits, re-escaneo bajo demanda, tolerancia a fallos del pipeline) y que el admin tenga una sub-ventana para gobernar el escaneo (frecuencia, alcance, estado, disparo manual).

Non-Goals: ver proposal.md.

## Decisions

### D1 — Comando `avisos:scan` como única entrada al escaneo

`ScanMentionsCommand` (signature `avisos:scan`) encapsula toda corrida: cron y manual pasan por el mismo lugar. Selección de objetivos:

```sql
SELECT t.* FROM transcriptions t
JOIN files f ON f.id = t.file_id
LEFT JOIN segment_keyword_hits h ON h.transcription_id = t.id
WHERE t.state = 'done' AND t.generate_alerts = true
  AND h.id IS NULL                       -- sin ningún hit
  AND t.finished_at >= now() - interval  -- ventana configurada
ORDER BY t.finished_at ASC
LIMIT :batch
```

- `--limit=N` (default 50) acota cada corrida; corridas sucesivas drenan la cola (el LEFT JOIN los excluye al tener hits).
- `--storage=`, `--from/--to`, `--transcription=` filtran el lote.
- `--force` borra primero los hits de las transcripciones objetivo (re-escaneo; con confirmación en la sub-ventana si la estimación supera el lote).
- Cada transcripción pasa por `AvisosScanService::scanOne()` → `KeywordMatcher::run()`; errores por transcripción se atrapan y cuentan sin abortar la corrida.

Alternativas rechazadas: (a) re-disparar el processor — toca un servicio crítico del pipeline por un propósito distinto; (b) scan en cola — innecesario a la escala actual; un CLI acotado con `withoutOverlapping` basta.

### D2 — Cron de tick fijo + decisión por settings (patrón sessions-cleanup)

`Schedule::command('avisos:scan')->everyFiveMinutes()->withoutOverlapping(15)` (tick fijo; TTL holgado). El comando decide internamente: si `avisos_scan_enabled=false` → salir; si `now() < last_successful_run + interval` → salir con motivo registrado en la corrida `skipped` (o solo log, sin fila — ver D4). Igual que `sessions_cleanup_interval_minutes`: la frecuencia real vive en settings, la expresión cron es fija.

### D3 — `AvisosScanService` (módulo profundo del escaneo)

Nueva clase en `app/app/Services/Ia/`:

```
AvisosScanService
├── run(array $opts): ScanResult      ← única entrada (cron y manual)
│     opts: origin(cron|manual), storageId, from, to, transcriptionId, limit, force, dryRun
├── selectCandidates(opts): Collection
├── scanOne(Transcription): int (hits nuevos; try/catch por transcripción)
├── settings(): {enabled, intervalMinutes, windowHours}  (SystemSetting)
├── saveSettings(array)                (valida mínimos: interval ≥ 5)
└── lastRun(): ?ScanRun
```

`ScanResult` (array/DTO): mode, scanned, hitsNew, failed, durationMs, status(success|failed|skipped), error. La tabla `avisos_scan_runs` persiste cada corrida con esos campos + `origin`, `params` (JSON), `started_at`, `finished_at`.

El comando delega aquí; el controlador admin también: la sub-ventana y el cron comparten una sola implementación (localidad).

### D4 — Corridas omitidas: log, no fila

Un tick que decide "no toca" NO crea fila en `avisos_scan_runs` (inundaría la tabla cada 5 min): solo `Log::info('avisos.scan.skipped')` con motivo. Solo las corridas REALES (ejecutadas) crean fila. La sub-ventana muestra "última corrida" desde la tabla y el próximo tick estimado desde el setting + `lastRun`.

### D5 — Sub-ventana "Escaneo" en la vista admin

Nueva pestaña en `ia/avisos-inteligentes/index.blade.php` (la vista actual es una tabla de clientes; se añade un switch de vista o tabs). Contenido:

- Switch "Escaneo automático" + inputs `interval_minutes` (number, min 5) y `window_hours` (number, min 1) → `PUT /ia/avisos-inteligentes/scan/settings`.
- "Escanear ahora": inputs opcionales (storage select, from/to date, checkbox forzar) → `POST /ia/avisos-inteligentes/scan/run` (sincrónico con límite de lote; respuesta con resumen). Si la estimación supera el lote → pide confirmación (estimación = COUNT con los filtros, consulta acotada con índices por `state`+`finished_at`).
- Estado: tarjeta con última corrida + tabla de las últimas 10 corridas (`GET /ia/avisos-inteligentes/scan` devuelve settings + runs en una sola respuesta).

Sin build step; mismo stack (Blade + Alpine + Tailwind). La ventana sigue el patrón visual del módulo.

### D6 — Migración `avisos_scan_runs`

```
id, origin (cron|manual), status (success|failed|skipped), params JSON,
transcriptions_scanned, hits_new, failed_count, duration_ms,
error TEXT NULL, started_at, finished_at, created_at
```

Índices: `(origin, created_at)`, `(status, created_at)`. Sin FK (registro operativo, purgable con retención futura si crece).

### D7 — Guardarraíles operativos

- Consultas de selección acotadas: filtran por `finished_at >= ventana` (índice utilizable), `LIMIT` duro; sin `SELECT` del histórico completo.
- `--dry-run` (cron y manual) cuenta candidatos sin escanear.
- Log de eventos: `avisos.scan.run` (resumen), `avisos.scan.skipped` (motivo), `avisos.scan.error`.
- El escaneo JAMÁS llama a `AlertDispatcher` ni encola correos: separación estricta scan→entrega (ya resuelta por `avisos:deliver-alerts`).
- El cron de tick convive con el disparo del pipeline: si el pipeline ya generó hits, el LEFT JOIN excluye la transcripción; cero doble trabajo.

## Risks / Trade-offs

- [Backfill masivo accidental] → ventana por defecto 72h + lote 50 por corrida + confirmación con estimación en forzado/manual masivo; `--force` solo explícito.
- [Tick cada 5 min con settings cambiantes] → misma limitación documentada de sessions-cleanup: aceptada y comentada en `routes/console.php`.
- [Corridas concurrentes cron+manual] → `withoutOverlapping(15)` para el cron; el manual puede solaparse con el cron: el LEFT JOIN + UNIQUE triple hacen el solape inofensivo (doble escaneo como máximo idempotente).
- [Tabla de corridas crece] → 1 fila por corrida real (~288/día máx); retención futura opcional, fuera de scope.

## Migration Plan

1. Migración → comando → service → cron tick → endpoints → sub-ventana. Despliegue único, aditivo, sin cambios de conducta existente.
2. Rollback: desactivar `avisos_scan_enabled` (setting) — el cron deja de escanear sin revertir código.
3. Validación post-deploy: `avisos:scan --dry-run`, corrida manual de prueba, verificación de fila en `avisos_scan_runs`, grep de logs `avisos.scan.*`.

## Open Questions

- Ninguna que bloquee. El intervalo/ventana por defecto (30 min / 72h) son settings ajustables sin código.
