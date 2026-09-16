# Design — avisos-keyword-word-boundary-matching

## Context

Ver proposal.md (Why). Estado actual verificado con datos de producción:

- 3 motores PHP matchean por `str_contains` sobre texto normalizado: `KeywordMatcher` (activo), `MentionBackfillService` (backfill), `LegacyKeywordMatcher` (fallback).
- El visor del modal (JS, `mis-avisos/index.blade.php::highlightKeyword`) resalta por `indexOf` de subcadena — quinto punto de matching, en cliente.
- Keyword 49 "Petro": 8,648 hits, ~2,929 falsos (petróleo/Petromil/petrolero/...), todos con `alert_deliveries` derivadas (8,648 entregas históricas).
- Prior art: `CorrectionService::isWordCharAt()` ya resolvió el problema idéntico (byte UTF-8 vs carácter) con regresión testada (`CorrectionUtf8BoundaryTest`).
- La mutación de watermarks DEBE pasar por `WatermarkReconciler` (convención de AGENTS.md; NUNCA SQL inline).

## Goals / Non-Goals

**Goals**: frontera de palabra en los 3 motores PHP + visor; re-indexación limpia de keywords ya escaneadas; guardrail de longitud mínima; tests de regresión al estilo del prior art.

**Non-Goals**: ver proposal.md (sin subpalabras, sin modos de match configurables, sin des-enviar alertas, sin cambio de normalización).

## Decisions

### D1 — Frontera con verificación de carácter adyacente (no regex de reemplazo)

Implementar una función compartida `KeywordBoundaryMatcher::matchesWord(string $haystackNorm, string $needleNorm): int` que devuelve el NÚMERO de apariciones con frontera, y `firstPosition()` para snippets/resaltado:

```
str_contains (fast-path, ya existe)
   └─ si matchea → escanear bordes del match con lógica
      estilo isWordCharAt: recortar el carácter UTF-8 completo
      del borde (no el byte) y evaluar contra /[\p{L}\p{N}]/u
   └─ seguir escaneando desde la posición siguiente hasta agotar
```

**Alternativas descartadas:**
- `preg_match_all` con lookarounds por (segmento × keyword): ~2-3× más caro y duplica la lógica de normalización en regex. Se descarta el regex como path principal; queda documentado como opción si el escaneo manual de bordes resulta lento.
- `\b` de PCRE: no fiable con UTF-8 en este contexto (depende de la tabla de locale y con texto ya transliterado a ASCII no aporta sobre la verificación directa).

**Por qué contar apariciones en la misma pasada**: `occurrences` y la posición para `buildSnippet` salen del mismo barrido; evita contar dos veces con lógica divergente.

### D2 — Un helper compartido, no tres implementaciones

Los 3 motores + backfill usan el mismo helper (`App\Services\Ia\KeywordBoundaryMatcher`, clase estática pura, testeable sin BD). El `LegacyKeywordMatcher` también se corrige: si el operador hace rollback a legacy, el bug reaparecería en silencio.

### D3 — Re-indexación con comando dedicado `avisos:rescan-keyword`

Flujo del comando (por keyword):
1. Contar hits a eliminar (`--dry-run` reporta y termina).
2. `DELETE FROM segment_keyword_hits WHERE keyword_id = ?` (chunked).
3. Para cada storage con watermark del par: `WatermarkReconciler::rewindPair(...)` (auditoría `action=rewind_pair` automática).
4. Lanzar el re-scan por pares vía `AvisosScanService::scanPair($t, $kw, force: false)` (los hits ya se borraron; force redundante) sobre las transcripciones candidates del storage según watermark rebobinado — reutiliza el barrido por pares del scan mensual existente.

**Alternativa descartada**: re-escanear TODO el histórico de todos los storages con `--force` del full-scan mensual — sobrecarga innecesaria para un fix que solo afecta keywords específicas.

### D4 — El visor JS aplica frontera solo para la keyword del hit

`highlightKeyword` distingue `tm.hitKeyword` (frontera, coincide con `occurrences`) de `tm.search` (subcadena libre, comportamiento actual). La frontera en JS: comprobar charCode del vecino contra `/[a-z0-9áéíóúüñ]/i` sobre el texto normalizado — el visor ya normaliza con `this.norm()` (mapa de equivalencias con tilde), no requiere librería nueva.

### D5 — Longitud mínima: 3 caracteres normalizados, en ambos controllers

Validación en `MisAvisosController::storeKeyword` y `AvisosInteligentesController` (store): `'min:3'` NO basta (cuenta bytes/caracteres del texto crudo con espacios) — validar `mb_strlen(Keyword::normalize($text)) >= 3` con mensaje en español. Las keywords existentes más cortas NO se tocan (el guardrail es solo en creación).

## Risks / Trade-offs

- [Keywords legítimas cortas bloqueadas ("OEA" pasa con 3; "AI" no)] → el mínimo de 3 es el compromiso aceptado por el operador; subir/bajar es una constante, no un rediseño. Keywords existentes no se invalidan retroactivamente.
- [Derivaciones legítimas dejan de matchear ("petrista", "petrismo")] → comportamiento deseado para "petro"; si algún cliente quiere el derivado, crea la keyword (cuenta contra su cuota — decisión de proposal).
- [Re-scan masivo sobre storages grandes] → reutiliza el barrido por pares acotado por watermark; el comando acepta `--dry-run` y registra progreso; el patrón mensual (`DB::disconnect + sleep`) no es necesario porque el trabajo por par es acotado.
- [Hits eliminados con entregas ya enviadas: contadores del feed caen de golpe] → esperado y deseado; comunicar en el deploy que el feed de "petro" se reducirá ~34%.
- [`occurrences` recalculado solo para hits re-indexados] → los hits de otras keywords conservan su conteo substring histórico; aceptable (sus keywords matchearon bajo la regla vigente en su momento).

## Migration Plan

1. Deploy de código (helpers + controllers + comando). Sin migración de esquema.
2. Ejecutar `avisos:rescan-keyword petro --dry-run` (verifica conteo esperado ≈ 2,929).
3. Ejecutar `avisos:rescan-keyword petro`.
4. Verificación: `occurrences` y hits de "petro" coinciden con frontera; feed del cliente refleja la reducción.
5. **Rollback**: revert del deploy restaura el motor substring; los hits re-indexados NO se restauran automáticamente (la re-indexación fue una operación de datos), pero un nuevo scan con motor viejo los regeneraría. Watermarks rebobinados siguen siendo válidos bajo ambas reglas.

## Open Questions

Ninguna bloqueante. El mínimo de longitud (3) y la lista inicial de keywords a re-indexar (solo "petro" hoy; el comando es genérico para futuras) quedaron resueltos con el operador.