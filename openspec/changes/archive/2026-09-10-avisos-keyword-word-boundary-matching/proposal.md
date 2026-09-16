# Proposal — avisos-keyword-word-boundary-matching

## Why

El motor de menciones matchea keywords por **substring crudo** (`str_contains`), no por palabra completa. La keyword "petro" matchea "petróleo", "Petromil", "petrolero": en producción, 2,929 de los 8,648 hits de "petro" (~34%) son falsos positivos, todos derivados a `alert_deliveries` (correos ya enviados) y visibles en el feed del cliente. El módulo de correcciones resolvió este mismo bug con fronteras de palabra UTF-8 (`CorrectionService::isWordCharAt`, regresión `CorrectionUtf8BoundaryTest`); el motor de avisos quedó sin esa protección.

## What Changes

- **Matching por palabra completa**: `str_contains` → verificación de fronteras de palabra Unicode-safe (`(?<![\p{L}\p{N}])` … `(?![\p{L}\p{N}])`), con el substring match retenido como pre-filtro rápido. Aplica a los 3 motores activos:
  - `KeywordMatcher::run()` (motor universal activo)
  - `MentionBackfillService::scanKeyword()` (backfill retroactivo)
  - `LegacyKeywordMatcher::run()` (fallback de rollback, `config('avisos.engine')`)
- **Re-escaneo con force de keywords afectadas**: comando dedicado que borra hits por keyword y re-indexa con el nuevo motor (mecanismo existente: `AvisosScanService::scanPair(force: true)` + `keyword_scan_watermarks` rewound vía `WatermarkReconciler::rewindPair`). Limpia los ~2,929 hits falsos de "petro".
- **Guardrail anti-abuso en creación de keywords**: longitud mínima (3 caracteres del texto normalizado) en `MisAvisosController::storeKeyword` y `AvisosInteligentesController` (keyword "el"/"a" matchearía casi todo el corpus).
- **Descarte explícito de la feature "subpalabras/variantes"**: las variantes de una keyword cuentan 1:1 contra la cuota (o sea, son keywords normales). La cuota por keyword (`keywords_quota`) es el tope de coste computacional del scan — variantes gratis anularían ese tope.

## Non-goals

- NO se agrega UI de "subpalabras relacionadas" ni modos de match configurables por keyword.
- NO se des-envían alertas ya entregadas (`alert_deliveries` históricas quedan como están).
- NO se cambia la normalización `asciiLower()` ni la tabla `keywords`.

## Capabilities

### New Capabilities

- `avisos-keyword-word-boundary`: comportamiento de fronteras de palabra en el matching de menciones, incluida la re-indexación de keywords ya escaneadas.

### Modified Capabilities

- `universal-matching-engine`: el requisito de persistencia de coincidencias ahora implica matching por palabra completa (los hits falsos por prefijo/subcadena dejan de persistirse).
- `mention-occurrence-detail`: el conteo de `occurrences` por segmento pasa a contar solo apariciones con frontera de palabra.

## Impact

- **Código**: `app/app/Services/Ia/KeywordMatcher.php`, `app/app/Services/Ia/MentionBackfillService.php`, `app/app/Services/Ia/LegacyKeywordMatcher.php`, `app/app/Http/Controllers/MisAvisosController.php`, `app/app/Http/Controllers/Ia/AvisosInteligentesController.php`.
- **Comando nuevo**: `avisos:rescan-keyword {keyword} {--force}` (re-scan + rewound watermarks).
- **Datos**: 2,929 hits falsos en `segment_keyword_hits` (keyword 49 "Petro") se eliminan al re-escanear. `alert_deliveries` históricas no se tocan (no des-enviables).
- **Migración**: NO requiere. Cambio de lógica + comando de mantenimiento.
- **Riesgo de coste**: la verificación de frontera añade regex solo sobre segmentos que ya matchearon por substring (fast-path conservado); sin impacto medible en el throughput del scan.