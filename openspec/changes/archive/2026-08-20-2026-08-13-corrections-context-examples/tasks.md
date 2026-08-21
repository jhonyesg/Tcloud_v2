# Tasks: Ejemplos de contexto en transcripciones

## 1. Backend — servicio de búsqueda

- [x] `CorrectionContextFinder` con `examples(Correction): array` → `{status, examples, truncated, probe}`.
- [x] Sonda según estado: `correct_text` si aprobada, `wrong_text` si no; fallback a `wrong_text` si la aprobada da 0 filas.
- [x] Guarda de longitud mínima (3): devuelve `too_short` sin consultar la BD.
- [x] Consulta con la condición indexable sobre `text` y `text_raw` como filtro secundario. Sin `ORDER BY`.
- [x] `SET LOCAL statement_timeout` dentro de transacción; `QueryException` con SQLSTATE 57014 → `timeout`.
- [x] `escapeLike()` para `\`, `%`, `_`, siempre con binding.
- [x] Deduplicación por `transcription_id` en PHP.
- [x] Caché con TTL configurable, clave con `updated_at`; los `timeout` no se cachean.

## 2. Backend — filtro de ejemplos engañosos

- [x] `CorrectionService::applyRule(Correction, string): string` público, delegando en `applyTextWithPairs()`.
- [x] Descartar segmentos donde `applyRule()` no cambia nada.
- [x] Exponer el resultado como campo `preview` de cada ejemplo.

## 3. Backend — endpoint

- [x] `CorreccionesController::contextExamples()`.
- [x] Ruta `GET /ia/correcciones/{id}/contexto` con `whereNumber('id')`, dentro del grupo `auth`+`admin`.

## 4. Config

- [x] Bloque `context` en `config/corrections.php`: `examples`, `scan_limit`, `timeout_ms`, `cache_ttl`, `min_probe_length`.
- [x] Documentar las vars en `.env.example`, avisando de no bajar `min_probe_length` de 3.

## 5. UI

- [x] Columna "Contexto" con botón "Ver ejemplos" en Pendientes (`hidden md:table-cell`) y Aprobadas (`hidden lg:table-cell`).
- [x] Modal con estados `loading`, `ok`, `too_short`, `no_matches`, `timeout` (este con botón de reintento).
- [x] Tarjeta por ejemplo: timestamp, enlace al archivo, "como lo transcribió" y "cómo quedaría con esta regla".
- [x] Helpers `openContext`, `closeContext`, `escapeHtml`, `highlightMatches` con fronteras `\p{L}\p{N}` y escape previo.
- [x] Acceso también desde el modal de edición.

## 6. Comando

- [x] `corrections:warm-context --status --limit` para precalentar la caché.

## 7. Verificación

- [x] `php -l` en todos los PHP; blade compila (`view:cache`); JS válido (`node --check`).
- [x] `EXPLAIN` del SQL real generado: usa `idx_transcription_segments_text_gin`, sin `Seq Scan`.
- [x] Correcciones reales: `#3094` pendiente, `#127` y `#331` aprobadas → `ok`, 220 ms–2,1 s.
- [x] `timeout_ms=1` → `timeout`, no cacheado; reintento con 10 s → `ok`.
- [x] Sonda de 2 caracteres → `too_short` con 0 consultas a `transcription_segments`.
- [x] Endpoint: anónimo 302, admin 200 con las 9 claves; id inexistente → `ModelNotFoundException` (404).
- [x] Ruta con middleware `web → auth → admin`.
- [x] `CorreccionesContextTest`: 9 tests, 20 aserciones, verde.
- [x] Casos límite del resaltado: mayúsculas, múltiples ocurrencias, `diseño`/`veintiséis`, escape HTML, metacaracteres.
- [ ] Revisión visual en navegador (pendiente del usuario).

## Notas

Los tests no cubren la consulta ILIKE en sí: la suite es deliberadamente sin BD (`LaravelTestCase`) y la base de testing no tiene `transcription_segments`. Esa parte se verificó con `EXPLAIN` y ejecución contra el corpus real.

Dos tests **preexistentes** fallan en el módulo, ajenos a este change: `CorreccionesRiskLevelTest::test_apply_retroactively_accepts_include_high_risk` (espera 5 parámetros, hay 11) y `AiSuggestCommandTest::test_correction_service_has_ai_suggest_method` (espera 3, hay 4). Son aserciones de reflexión que quedaron desfasadas al ampliar esas firmas en changes anteriores.
