## Context

El módulo admin `ia/avisos-inteligentes/{userId}` consulta `User::keywordMatches()` (modelo legacy con tabla vacía) y renderiza con `@foreach($matches as $match)`. Eso genera dos problemas:

1. La vista NO muestra datos reales.
2. Cuando se llene de datos, la vista va a reproducir exactamente la misma incoherencia "filename, mention, filename, mention" que el cliente vivía antes del fix de Histórico. 

Adicionalmente, `KeywordMatcher::run()` tiene una idempotencia per-transcription que hace que las keywords tardías no se escaneen contra las 359K transcripciones existentes.

## Goals / Non-Goals

**Goals:**
- El admin ve los matches del cliente exactamente con la misma UX que el cliente usa en Histórico.
- Las keywords nuevas se escanean contra TODAS las transcripciones accesibles.
- Un comando artisan permite poblar retroactivamente.
- Crear una keyword dispara el backfill sin bloquear al cliente.

**Non-Goals:**
- No se rediseña el search engine interno (substring normalizado sigue siendo el método).
- No se elimina `keyword_matches` (queda legacy vacía para compatibilidad).
- No se introduce pg_trgm o full-text-search.

## Decisions

### 1. UI server-side en admin (no Alpine)
**Decisión:** el admin renderiza groups en PHP puro, con Blade + `<details>` HTML nativo para el expand. Más simple que arrastrar Alpine al blade del admin y mantiene consistencia con cómo `ia/avisos-inteligentes/index.blade.php` ya está estructurado.

**Alternativas:**
- *Reusar mismo componente Alpine que Mis Avisos* — descartado: requiere reescribir más blade del admin y el admin no usa Alpine extenso.
- *Vue.js* — descartado: el proyecto no usa Vue (Tailwind+Alpine vanilla).

### 2. Grouping server-side PHP
**Decisión:** extraer un helper `groupHitsByTranscriptionKeyword(Collection $hits): Collection` que devuelva una `Collection` con keys `{transcription_id}:{keyword_id}`, valor un `Collection` con las filas + `first_matched_at`, `count`, etc. Reusable para futuras vistas.

**Por qué:** la lógica de `_groupRows` (Alpine) y este helper PHP son equivalentes pero en distinto lenguaje. Mantenerlos sincronizados via tests de paridad. La reutilización real viene cuando otro flujo necesite agrupar matches server-side.

### 3. Backfill: background job + comando manual
**Decisión:**
- Un comando artisan `mentions:backfill-keyword` que itere manualmente (sin crons automáticos ocultos).
- Un `Job` `BackfillKeywordMatches` que el cliente dispara al crear una keyword vía `MisAvisosController::storeKeyword`.

**Por qué no cron automático:** las keywords del cliente tienen cadencia irregular (a veces 1 keyword nuevo cada 2 meses). El cron mantendría al backend revisitando scans viejos innecesariamente. Mejor explícito.

### 4. Idempotencia per-(transcription, keyword)
**Decisión:** cambiar la query en `KeywordMatcher::run()`:
```php
$existingKeywordIds = DB::table('segment_keyword_hits')
    ->where('transcription_id', $transcription->id)
    ->pluck('keyword_id')
    ->all();
$missing = $keywordIdByNorm->keys()->diff($existingKeywordIds);
if ($missing->isEmpty()) return 0;  // fully scanned
foreach ($missing as $keywordNorm) {
    ...
}
```

**Por qué:** permite que el motor SKIP keywords ya escaneadas en esta transcripción, pero que ESCANEE keywords nuevas. Bug fix correcto, no workaround.

### 5. Auto-backfill en storeKeyword (sin bloquear UX)
**Decisión:** `storeKeyword` despacha `BackfillKeywordMatches->dispatch($keywordId)->onQueue('default')` y devuelve 201 inmediatamente. El cliente ve el keyword creado al instante; el backfill corre en background (job queue 'default').

**Por qué:** el cliente decide "ya tengo mi keyword" → quiere verla, no esperar 30s al backfill. El backfill retroactivo es un bonus, no un requisito de la creación.

## Risks / Trade-offs

- **[Risk] El backfill automático de storeKeyword crea carga en el queue worker.** → Mitigation: el job usa chunking (500 inserts por iteración) y respeta el `insertOrIgnore` (idempotente). En la práctica, escanear una keyword contra 51M segmentos toma ~5 min en background.

- **[Risk] La idempotencia per-(transcription, keyword) puede romper el contrato de "si añado keyword A, los hits de A en transcripciones viejas se computan en N background tasks en lugar de 1."** → Mitigation: el backlog es el mismo, solo distribuido. El cliente no espera; por eso el cambio es favorable.

- **[Risk] Reusar `<details>` HTML para expand en el admin puede tener estilos inconsistentes con el resto del admin panel.** → Mitigation: usar clases Tailwind explícitas que armonicen con el resto de la vista.

## Migration Plan

1. **Fase 1** (UI): cambiar controller + Blade. Cero migrations. Cero riesgo en datos — solo se cambia de dónde se lee.
2. **Fase 2** (idempotencia): cambiar `KeywordMatcher::run()`. Cero migrations. La nueva query es más estrecha (subset del check anterior).
3. **Fase 3** (backfill): crear job + comando + integración. Cero migrations. Insert vía `insertOrIgnore` (UNIQUE constraint blinda contra duplicados).

**Rollback**: revertir controllers/blade/commit. La tabla no se toca.

## Open Questions

- ¿El comando artisan `mentions:backfill-keyword --all` debe correr en foreground (esperar al cliente) o `--queued`? Recomiendo foreground con flag `--quiet` para que el cliente vea progreso.
