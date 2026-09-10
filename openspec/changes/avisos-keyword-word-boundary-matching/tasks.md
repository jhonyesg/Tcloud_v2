# Tasks — avisos-keyword-word-boundary-matching

## 1. Helper compartido de frontera

- [x] 1.1 Crear `app/app/Services/Ia/KeywordBoundaryMatcher.php` con métodos estáticos: `matchesWord(string $haystackNorm, string $needleNorm): bool`, `countOccurrences(...): int`, `firstPosition(...): ?int` — escaneo de bordes carácter-UTF-8-completo (estilo `CorrectionService::isWordCharAt`, evaluar `\p{L}\p{N}` por carácter, no por byte), con fast-path `str_contains` previo.
- [x] 1.2 Crear `app/tests/Unit/KeywordBoundaryMatcherTest.php` con casos del spec: "Petro"✅, "Gustavo Petro"✅, "petróleo"❌, "Petromil"❌, "petrismo"❌, "subpetrolero"❌, "Petro,"✅, adyacente acento UTF-8 ("áPetro"❌ por frontera izq con carácter multibyte), conteo mixto ("petro, petrolero y Petromil" → 1), posición para snippet.

## 2. Motores PHP

- [x] 2.1 `KeywordMatcher::run()`: reemplazar `str_contains` por `KeywordBoundaryMatcher` (hit + `occurrences` + `buildSnippet` usan posición del helper).
- [x] 2.2 `MentionBackfillService::scanKeyword()`: idem — el pre-filtro SQL `LIKE` puede quedarse (subcadena es superset), la aceptación final usa frontera.
- [x] 2.3 `LegacyKeywordMatcher::run()`: idem (fallback de rollback no debe resucitar el bug).
- [x] 2.4 Verificar que `AvisosScanService::scanPair()` hereda el comportamiento sin cambios (delega en `KeywordMatcher`).

## 3. Visor JS

- [x] 3.1 En `mis-avisos/index.blade.php::highlightKeyword`, aplicar frontera de palabra al `collect(tm.hitKeyword, 'kw')` (vecino contra `/[a-z0-9áéíóúüñ]/i` sobre el texto normalizado por `this.norm()`), dejando `tm.search` por subcadena libre; conteo de `<mark>` kw coincide con `occurrences`.

## 4. Guardrail de creación

- [x] 4.1 `MisAvisosController::storeKeyword` y `AvisosInteligentesController` (store): validar `mb_strlen(Keyword::normalize($text)) >= 3` con mensaje de validación en español; extraer `$text = $validated['text'] ?? null` antes de usar (convención AGENTS.md para claves opcionales).
- [x] 4.2 Test de feature/validación: keyword "el"/"a" rechazada sin consumir cuota; "petro" aceptada; no invalida keywords cortas existentes.

## 5. Comando de re-indexación

- [x] 5.1 Crear `app/app/Console/Commands/AvisosRescanKeywordCommand.php` (`avisos:rescan-keyword {keyword} {--dry-run} {--user=}`): dry-run cuenta hits a eliminar; sin dry-run: DELETE chunked de hits de la keyword → `WatermarkReconciler::rewindPair` por cada par (auditoría automática) → re-scan por pares reutilizando el barrido existente (`AvisosScanService::scanPair`).
- [x] 5.2 Registrar el comando en el bootstrap de consola y documentar uso en AGENTS.md (sección runbook de avisos).

## 6. Verificación y limpieza de datos

- [x] 6.1 Correr suite de tests nuevos + los existentes relacionados (`AvisosScanServiceBumpDedupeTest`, `CorrectionUtf8BoundaryTest` intacto) y lint/phpcs si aplica.
- [x] 6.2 En producción: `avisos:rescan-keyword petro --dry-run` (verificar conteo ≈ 2,929) → ejecutar sin dry-run → verificar en BD que los hits de "petróleo/Petromil/..." desaparecieron y los de "Petro" palabra completa persisten; confirmar que el feed del cliente refleja la reducción.
- [x] 6.3 Verificar auditoría: `watermark_audit_log` registra `rewind_pair` con actor para los pares de la keyword 49.