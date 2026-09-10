## Why

El escaneo actual de avisos inteligentes re-evalúa transcripciones que ya tienen hits de keywords distintas, no rastrea cobertura por keyword nueva, y no distingue "cliente nuevo desde hoy" de "cliente que pidió histórico". Una keyword agregada HOY no recibe retroactivo sobre las 1.393 transcripciones ya procesadas porque `selectCandidates` las excluye al tener cualquier hit previo. Un "escaneo completo" manual recorre 351k candidatos sin manera de saber qué keyword×storage ya está cubierta, gastando recursos en redundancia y abriendo ventanas de carrera donde keywords agregadas a media corrida quedan parcialmente cubiertas. Hoy hay 351.435 transcripciones pendientes con 54 keywords (36 sin hits aún) — el modelo no escala.

## What Changes

- Nueva tabla `keyword_scan_watermarks(keyword_id, storage_provider_id, scanned_until, last_scan_run_id, last_hit_at, candidates_total, hits_total)` con PK compuesta e índices para barrido por storage.
- `selectCandidates()` deja de filtrar "transc sin ningún hit" y pasa a filtrar por par `(transc, keyword)`: candidatos = `(transc, keyword)` cuyo `t.finished_at >= COALESCE(w.scanned_until, '-infinity')` y sin hit previo en `segment_keyword_hits` para esa keyword.
- `run()` actualiza el watermark por `(keyword_id, storage_provider_id)` de forma atómica tras procesar: `UPSERT ... SET scanned_until = GREATEST(scanned_until, t.finished_at)` (idempotente, monotónico, race-safe).
- Al insertar una nueva `keyword` o asignar un `user_keyword`, se crea el watermark correspondiente con `scanned_until = NULL` para sus storages con acceso — habilita catch-up automático sin intervención.
- Nuevo modo de corrida "full scan" explícito: barre TODAS las `(keyword, storage)` con watermark atrasado, reporta progreso, reanuda tras corte.
- Settings adicionales: `avisos_scan_full_batch` (default 200) y `avisos_scan_full_max_runtime_seconds` (default 600) para acotar corridas masivas.
- Migración inicial: inferir `scanned_until` por `(keyword, storage)` desde `MIN(t.finished_at)` de las transcripciones que ya tienen hits — sin re-escaneo.

## Capabilities

### New Capabilities
- `avisos-keyword-storage-watermark`: tracking de cobertura de escaneo por `(keyword_id, storage_provider_id)` con semántica de cursor monotónico, modo "full scan" reanudable, y reglas de derivación de watermark al alta/baja de keywords y asignación keyword→storage.

### Modified Capabilities
- `avisos-scan-configuration`: la selección de candidatos pasa de "transcripción sin hits" a "transcripción × keyword sin hit, dentro de la cobertura del watermark"; se añade el modo "full scan" con reporte de progreso.
- `universal-matching-engine`: el motor reusa el watermark para omitir `(transc, keyword)` ya cubiertos; se documenta que el barrido inicial de una keyword nueva se hace por watermark, no por trigger.

## Impact

- Migración nueva: `2026_09_10_<id>_create_keyword_scan_watermarks_table.php`.
- Modificación: `app/app/Services/Ia/AvisosScanService.php` (`selectCandidates`, `run`, `scanOne`).
- Modificación: `app/app/Services/Ia/KeywordMatcher.php` (`run` recibe hint del watermark).
- Modificación: `app/app/Models/Keyword.php` (evento al crear: inserta watermarks para storages con acceso).
- Modificación: `app/app/Console/Commands/ScanMentionsCommand.php` (opciones `--full`, `--keyword-id`, `--storage-id` y settings nuevos).
- UI: sub-ventana `/ia/avisos-inteligentes` muestra estado de cobertura por keyword.
- Tests de integración: harness que verifica idempotencia del watermark tras corridas concurrentes simuladas.
