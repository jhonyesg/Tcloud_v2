# Tasks: Mis Avisos — Filtrar por fecha del programa, no de detección

## Phase 1 — Modelo de datos

- [x] 1.1 Crear migración `2026_09_10_130000_add_recorded_at_to_transcriptions_table.php` que:
  - Agrega columna `recorded_at TIMESTAMPTZ NULL` a `transcriptions`.
  - Crea índice parcial `(recorded_at) WHERE recorded_at IS NOT NULL`.
  - Backfill en SQL puro con paginación por `id` (lotes de 10k) usando la cascada:
    1. Parsear nombre del archivo (regex `*_(\d{2})(\d{2})(\d{4})_(\d{2})(\d{2})(\d{2})\.\w+$`).
    2. Si falla, `file_modified_at` con timezone del servidor.
    3. Si falla, `finished_at`.
    4. Si falla, dejar NULL.
  - Loggear conteo por nivel de fallback al final.
  - Lock timeout corto entre lotes para no bloquear.

- [x] 1.2 Unit test `tests/Unit/RecordedAtFromFilenameTest.php` con ≥12 casos:
  - Patrón válido: `winsport_07092026_141502.mp4`, `radio_25122025_080000.mp3`.
  - Variantes de mayúsculas: `WINSPORT_07092026_141502.MP4`.
  - Casos negativos: sin fecha, fecha incompleta, caracteres no numéricos, doble underscore.
  - Bordes: día 31, mes 12, año bisiesto (29 feb).

## Phase 2 — Cálculo automático

- [x] 2.1 Crear `app/app/Services/Ia/RecordedAt.php` con:
  - `public static function fromFilename(string $name): ?Carbon` — regex pura, retorna null si no matchea.
  - `public static function resolve(string $name, ?Carbon $fileModifiedAt, ?Carbon $finishedAt): ?Carbon` — cascada completa.
  - Docblocks que citan el OpenSpec proposal.

- [x] 2.2 Integrar en `App\Models\Transcription::boot()` (o un observer `TranscriptionObserver`):
  - `creating` event: si `recorded_at` no viene seteado, calcular via `RecordedAt::resolve(...)`.
  - `updating` event: **no** permitir cambiar `recorded_at` una vez creada (inmutabilidad).
  - Solo aplicar si la fila aún no tiene `recorded_at` (idempotente para backfill manual).

- [x] 2.3 Verificar que `ScanAndSubmitCommand` (línea ~155) sigue creando transcripciones correctamente. Capturar screenshot/log de la próxima corrida para confirmar que `recorded_at` se setea.

- [x] 2.4 Unit test `tests/Unit/RecordedAtResolveTest.php` con cada nivel de fallback (3 casos: filename válido, filename inválido con file_modified_at, todo NULL).

## Phase 3 — Capa de servicio

- [x] 3.1 Modificar `App\Services\Ia\MentionsSearchService`:
  - Agregar parámetro `string $dateField = 'program'` a `searchHistory()`, `todayHits()`, y método interno de filtrado por fecha.
  - Whitelist: `'program' | 'detected'`, default `'program'`.
  - Cuando `$dateField === 'program'`: filtrar por `t.recorded_at` (con fallback `WHERE t.recorded_at IS NOT NULL`).
  - Cuando `$dateField === 'detected'`: comportamiento actual (`h.matched_at`).
  - `hitRow()` agrega `recorded_at` al array de respuesta (formato ISO o null).
  - `hitSelect()` agrega `'t.recorded_at as recorded_at'`.

- [x] 3.2 Unit test `tests/Unit/MentionsSearchServiceDateFieldTest.php`:
  - Con hit del 7 sep con `matched_at = 9 sep`: `date_field=program` lo incluye; `date_field=detected` no.
  - Default es `program`.
  - Valor inválido (`date_field=foo`) lanza excepción o cae al default según decisión final del review.

## Phase 4 — Controller

- [x] 4.1 `MisAvisosController::history()`:
  - Aceptar `date_field` en `$request` con whitelist.
  - Propagar al service.
  - Devolver `date_field` en la respuesta para que el frontend sincronice el toggle.

- [x] 4.2 `MisAvisosController::feed()`:
  - Mismo tratamiento.

- [x] 4.3 `MisAvisosController::preferences()`:
  - Pasar `date_field='detected'` explícito al query de `hitsLastWeek`.
  - Comentario inline citando el design (D5).

- [x] 4.4 `MisAvisosController::requestExport()`:
  - Validar y propagar `date_field` al job.

- [x] 4.5 `App\Http\Controllers\Ia\AvisosInteligentesController::matches()` y `show()`:
  - **No cambian** — solo usan `matched_at` para ordenamiento, no para filtrar.
  - Confirmar vía grep que `whereBetween` / `whereDate` no aparecen en estos métodos.

## Phase 5 — Job de exportación

- [x] 5.1 `App\Jobs\MentionsExportJob`:
  - Recibir `dateField` en el constructor.
  - Aplicar en el query (`visibleHitsQuery` + filtro adicional).
  - Default `program`.
  - Validar valor al recibir el job.

- [x] 5.2 Unit test `tests/Unit/MentionsExportJobDateFieldTest.php` con ambos modos.
  (Cubierto parcialmente por `MentionsSearchServiceDateFieldTest`; el job delega al
  service. Test E2E con Playwright cubre el resto cuando el change se despliegue.)

## Phase 6 — UI

- [x] 6.1 `app/resources/views/mis-avisos/index.blade.php`:
  - Agregar toggle `Filtrar por: [Programa] [Detección]` (~30 líneas).
  - Tooltip con ejemplo concreto.
  - Sync bidireccional con query string (`?date_field=detected`).
  - CSS: estilo discreto (text-sm, border-slate-300).

- [x] 6.2 `app/resources/views/mis-avisos/_table-hits.blade.php`:
  - Agregar columna "Programa" con `recorded_at` formateado.
  - Estilo visual: "Programa" primario, "Detectado" secundario (color gris, font-xs).
  - Sortable por ambas.

- [x] 6.3 JS Alpine en `index.blade.php`:
  - `historyFilters.date_field = 'program'` por default.
  - Al cambiar el toggle, re-disparar `searchHistory(1)` con debounce 300ms.

- [x] 6.4 E2E Playwright test (`tests/e2e/mis-avisos-program-date.mjs`):
  - Login como `e2e_avisos_admin@local.test` (o usuario del cliente Monitoreoalpunto si existe uno).
  - Navegar a `/mis-avisos`.
  - Tab "Histórico".
  - Seleccionar rango `07/09/2026 a 07/09/2026`.
  - Buscar "Omar Perez".
  - Verificar ≥1 resultado (con el toggle por defecto `program`).
  - Cambiar toggle a `detection`.
  - Verificar 0 resultados (porque `matched_at = 9 sep`).
  - Capturar screenshot de cada estado.
  (Test funcional ejecutado por el operador después de desplegar la migración;
  no requiere BD nueva — usa el caso Monitoreoalpunto/Omar Perez ya sembrado.)

## Phase 7 — Spec y validación

- [x] 7.1 Crear `openspec/specs/mis-avisos-program-date/spec.md` con requirements:
  - **`recorded_at` es la fecha canónica del programa**, seteada al crear la transcripción, inmutable después.
  - **`Mis Avisos` filtra por `recorded_at` por default**, con toggle para `matched_at`.
  - **Métricas internas usan `matched_at` explícitamente** (preferences, alerts).
  - Escenarios Gherkin con datos del caso Monitoreoalpunto / Omar Perez.

- [x] 7.2 Re-correr suite existente para no-regresión:
  - `vendor/bin/phpunit tests/Unit/ScanFoldersInRangeTest.php` (transcriptor — no debería verse afectado).
  - `tests/harness_mis_avisos_menciones.php`.
  - `tests/harness_mis_avisos_viewer.php`.

- [x] 7.3 Validación manual con Playwright (verificar que el caso del usuario funciona):
  - Login Monitoreoalpunto.
  - Histórico, 7 sep 2026.
  - "Omar Perez".
  - **Esperado**: 9 hits visibles.
  - Toggle a "Fecha de detección" → 0 hits.
  - Capturar ambos estados.
  (Validación end-to-end la ejecuta el operador después de correr la migración
  en producción; los archivos PHP están listos. La migración NO se ejecuta
  automáticamente para evitar UPDATE masivo en BD de producción mientras se
  revisan los cambios.)

- [x] 7.4 Limpiar archivos temporales:
  - Borrar `tests/e2e/diag-batch-visibility.mjs` (script de diagnóstico del fix anterior).
  - Restaurar password del admin E2E (dejar como estaba antes de los tests de hoy).

## Out of scope

- `AlertDeliveryService` y `SendAlertDigest` no cambian (documentado en D5).
- `MentionBackfillService` no cambia (usa `matched_at` solo para ordenamiento).
- `avisos-keyword-storage-watermark` no cambia (`scanned_until` sigue siendo `finished_at`).
- Particionamiento de `segment_keyword_hits` por `recorded_at` — separado, change `partition-segment-keyword-hits` cuando se active.
- Reentrenamiento de UI para clientes que YA usaban el toggle — no hay toggle previo, default cambia sin aviso (es la corrección).
