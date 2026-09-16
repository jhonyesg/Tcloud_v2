# Design — transcriptor-scan-scope-selector

## Context

Ver proposal.md. Datos verificados en producción (2026-09-10):

- 548,989 archivos sin transcripción en storages habilitados (incluye carpetas de hoy/recientes sin transcripción aún); el **backlog histórico** (carpetas `dmY` con ≥2 días de antigüedad, archivo vivo) es de **15,703 archivos** en ~80 storages, el más viejo del 2026-08-01.
- 62 carpetas `dmY` distintas existen. El formato es `DDMMYYYY` (convención de `dayFolders`).
- Estados: 357,088 done / 17,435 dead / 1,243 error / pending+queued+processing = 13.
- `transcription:backfill-lost --dry-run` reporta **0 upstream-lost** → los 17,435 `dead` son TODOS por audio ausente/corrupto o error definitivo: NO son recuperables por reintentos (re-morirían). Solo los `error` (1,243) son reintento realista vía `include-failed`.
- El comando CLI ya soporta `--days=N` y `--all` (`ScanAndSubmitCommand.php:17-18`); falta `--from/--to` y la UI.
- La estructura del runId en cache (`transcription_batch:{runId}`) ya existe y el widget global la lee.

## Goals / Non-Goals

**Goals**: alcance elegible en la UI (hoy/rango/todo), estimador previo, reintento de fallidos acotado por rango, progreso por storage en el modal.

**Non-Goals**: ver proposal.md (no re-transcribir `done`, no tocar el regulador, no cambiar el cron, no recuperar dead por audio ausente).

## Decisions

### D1 — Rango = lista explícita de carpetas `dmY` (no filtro por mtime)

`DiskScannerService::scanStorage($storage, $days, $all, ...)` firma extendida a `scanStorage($storage, $scope, ...)`, donde `$scope` es un objeto plano: `{mode: 'today'|'range'|'all', folders?: string[]}`. Para `range`, la carpeta se calcula en PHP: `iterar día por día desde $from hasta $to → 'dmY'` (mismo formato que `dayFolders`). Layout `flat` usa esas carpetas directas; `grouped_by_subfolder` las matchea por basename en la recursión (reutiliza `dayFoldersGrouped` con la lista de `dayNames` inyectada en vez de los últimos N días).

**Alternativas descartadas:**
- Filtrar por `filemtime` dentro de un rango: caminaría el árbol COMPLETO (548k archivos) para luego descartar — más caro que construir la lista de 62 carpetas conocidas.
- `--days=N` genérico: ya existe pero no permite rangos que NO terminan en hoy (ej. rescatar solo agosto).

### D2 — Estimador con 2 queries acotadas (files LEFT JOIN transcriptions)

`POST /ia/transcriptor/scan/estimate {mode, from?, to?}`:
- **Sin transcripción en el rango**: `SELECT f.storage_provider_id, COUNT(*) FROM files f LEFT JOIN transcriptions t ON t.file_id=f.id WHERE ... path LIKE 'folder/...' GROUP BY 1` — por storage, usando el prefijo de carpeta (índice en `files(path)` no existe; con la tabla acotada por storage y deleted_at es aceptable; la query se cachea 60s con la misma key pattern del funnel).
- **error/dead**: `SELECT state, COUNT(*) FROM transcriptions WHERE state IN ('error','dead') GROUP BY state` + dead upstream-lost vía `upstreamLost()` scope en PHP.
- Response: `{files_missing: N, error_recoverable: M, dead_lost: K, dead_irrecoverable: J, storages: [{id, name, missing}]}`.

**Guardrail de carga**: la query de files_missing usa `split_part(path, '/', 1) IN (lista de dmY)` en vez de LIKE % para mantener el predicado sargable; si el rango supera ~90 días y >50k archivos, la estimación se corta con `--limit` de seguridad y el modal muestra "≥N" (evita las consultas pesadas masivas que el operador ya prohibió en correcciones — misma disciplina aquí).

### D3 — UI: radio de alcance + estimación inline

En el modal actual (batch modal, blade ~1585-1650): un `<fieldset>` con 3 radios (Hoy/Rango/Todo), inputs date ocultos salvo en modo rango, y el bloque de estimación que se consulta al cambiar el alcance (debounce 400ms). El botón "Iniciar escaneo" muestra la estimación encima. Defaults: Hoy + batch actual + checkboxes actuales intactos — el flujo vigente no cambia ni un píxel.

### D4 — Progreso por storage reutilizando la infraestructura del runId

El cache `transcription_batch:{runId}` hoy tiene `storages[]`. Extensión mínima: al lanzar con rango/all, el controller escribe `mode`, `folders_per_storage` (nombre de carpetas por storage) y cada storage del loop del comando actualiza `progress: {storage_id, scanned, files_created, tx_created}` ANTES de pasar al siguiente (hoy el comando solo informa al final — cambio en `ScanAndSubmitCommand::runHandle` para escribir el cache intermedio por storage). El modal ya hace polling de `/batch-status/{runId}` — solo renderiza el campo nuevo.

### D5 — Reintentos de fallidos: mismo include-failed pero acotado

Fase 1.5 actual (`collectFailedCandidates`) itera TODOS los storages sin filtro temporal. Con el alcance elegido: si `mode != 'all'`, añade `whereDate('created_at', '>=', $from) & <= $to` (por `transcriptions.created_at` — más simple y ya indexado). Con `all`: sin filtro (comportamiento actual). Los `dead` NO se tocan en esta fase; el modal muestra un enlace informativo a `transcription:backfill-lost --audit` para los upstream-lost (hoy: 0).

## Risks / Trade-offs

- [Escaneo "todo" lento en storages con árbol profundo (depth 6)] → el progreso por storage permite al operador ver avance y detectar el storage lento; el descubrimiento es I/O-bound y corre en background (no bloquea FPM).
- [Estimación imprecisa si hay archivos nuevos entre estimación y lanzamiento] → aceptable: la estimación es guía de decisión, no contrato; el run reporta los números reales.
- [Rango mal especificado (from > to)] → validación client+server: 422 con mensaje si `from > to` o fecha futura.
- [Creación de ~15k File rows en el histórico] → idempotencia por (storage, path) UNIQUE ya existe (`files_storage_provider_id_path_unique`); el re-scan no duplica.
- [El operador lanza "todo" con batch alto y espera envío inmediato de todo] → el modal mostrará explícitamente "descubre X, envía Y por ciclo (regulador)". Copiar el copy del preview de avisos ("el resto queda pendiente para el cron").

## Migration Plan

1. Deploy de código (comando + service + controller + vista). Sin migración.
2. Smoke test CLI: `transcription:scan-and-submit --from=01082026 --to=03082026 --batch=10 --dry-run` (añadir flag `--dry-run` al comando si no existe: cuenta candidatos sin crear).
3. Smoke test UI: estimar rango pequeño (1 día), lanzar, ver progreso por storage en el modal.
4. Rollback: revert del commit; el alcance vuelve a ser solo-hoy; runs ya creados siguen en cache y se agotan solos (TTL 2h).

## Open Questions

Ninguna bloqueante: el rango por carpetas `dmY` quedó confirmado por la estructura real (62 carpetas, formato DDMMYYYY); el "todo" NO incluye re-transcribir `done` (confirmado por operador en la conversación).