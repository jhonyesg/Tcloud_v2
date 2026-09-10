# Tasks — transcriptor-scan-scope-selector

## 1. Alcance en el servicio de escaneo

- [x] 1.1 Definir `ScanScope` (clase o array shape `{mode: 'today'|'range'|'all', folders: string[]}`) y extender `DiskScannerService::scanStorage()` a esa firma; `today` delega a `dayFolders` (comportamiento intacto), `range` usa `foldersInRange(from, to)` (nuevo, mismo formato `dmY`), `all` delega a `allFoldersRecursive` (ya existe).
- [x] 1.2 En `dayFoldersGrouped()`, aceptar la lista de `dayNames` inyectada (range) en vez de derivarla de `daysBack` — sin romper el caller actual.
- [x] 1.3 Test unitario de `foldersInRange()`: from=01082026/to=03082026 → exactamente esas 3 carpetas; from>to → excepción; formato `dmY` correcto con mes cambiante (01312026 → 02012026).

## 2. Comando scan-and-submit

- [x] 2.1 Añadir opciones `--from=DDMMYYYY --to=DDMMYYYY` y `--dry-run` a `ScanAndSubmitCommand`; construir el `$scope` y pasarlo a `scanStorage` (mapear `--days`/`--all` al scope equivalente para compatibilidad).
- [x] 2.2 En `--dry-run`: recorrer storages contando candidatos (archivos `.mp4` sin File/transcripción) SIN crear filas; reportar por storage y total.
- [x] 2.3 Fase 1.5 (include-failed): si el scope es range, filtrar `transcriptions.created_at` dentro del rango (inclusive); si today/all, sin filtro nuevo.
- [x] 2.4 Progreso por storage: tras cada storage en el loop, escribir en el cache del runId `{storage_id, name, scanned, files_created, tx_created}` en el array `storages[]` existente (solo si runId presente).

## 3. Controller y estimador

- [x] 3.1 En `ApiTranscriptorController::processBatch()`: aceptar `scope: {mode, from?, to?}` del request; validar (from<=to, fechas válidas ddmmYYYY, to <= hoy); construir los flags del comando (`--from/--to` o `--all`); rechazar fechas futuras con 422.
- [x] 3.2 Crear `POST /ia/transcriptor/scan/estimate` con la query acotada de design D2 (files_missing por storage en el rango con predicado sargable, error_recoverable, dead_lost/dead_irrecoverable), guardrail de límite y cache 60s; retornar `{total, storages[], dead_info}`.
- [x] 3.3 Registrar la ruta del estimador en `routes/web.php` dentro del grupo del transcriptor.

## 4. Vista y Alpine

- [x] 4.1 En el modal del batch: fieldset de radios (Hoy/Rango de fechas/Todo el histórico) + inputs de fecha (solo rango) + bloque de estimación (se consulta on-change con debounce, spinner mientras).
- [x] 4.2 Render de la estimación: "X archivos sin transcribir · Y error recuperables · Z dead irrecuperables (no se reintentan)" + nota del regulador cuando X > cupo de la corrida.
- [x] 4.3 Progreso del modal: render del array `storages[]` del runId con scanned/files_created/tx_created por fila (el polling ya existe).
- [x] 4.4 Validación client-side: rango from<=to, fechas no futuras, botón deshabilitado mientras corre el batch (estado `batchRunning` ya existe).

## 5. Verificación

- [x] 5.1 Tests unitarios: constructor de carpetas del rango, dry-run no muta, estimador con guardrail (contar sin mutar), include-failed acotado por rango.
- [x] 5.2 Smoke CLI: `--from/--to` de 1 día conocido con archivos sin transcribir → dry-run reporta el conteo esperado; lanzamiento real crea solo esas filas.
- [x] 5.3 Smoke UI con Playwright: estimación de rango, lanzamiento, progreso por storage, segunda corrida del mismo rango = 0 creados.
- [x] 5.4 Confirmar que el flujo "Hoy" queda byte-a-byte igual (mismo comando, mismo modal, sin regressión del cron automático).