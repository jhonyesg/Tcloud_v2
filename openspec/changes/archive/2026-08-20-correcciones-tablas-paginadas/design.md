## Context

Ver proposal.md — Why. Los endpoints `GET /ia/correcciones/approved` y `GET /ia/correcciones/ai-suggest-results` devuelven el diccionario completo en un solo JSON (2.467 aprobadas, 2.263 de AI Suggest), y el filtrado ocurre en cliente sobre el array entero. El bulk ya está limitado en backend a `config('corrections.bulk_max_ids')` = 500, pero la selección en la UI solo cubre la página visible.

Stack: Laravel 13 + PostgreSQL, Blade + Alpine.js (CDN, sin build step), endpoints de `/ia/correcciones` con `apiFetch` + CSRF de sesión.

## Goals / Non-Goals

**Goals:**
- Paginación server-side (default 50/página) en las tablas "Aprobadas" y "AI Suggest Results".
- Búsqueda libre y filtro por `source` evaluados en servidor antes de paginar.
- Selección en lote acumulativa a través de páginas, con tope de 500.
- Payload de fuentes (`sources`) para poblar el dropdown sin barrer el array completo.

**Non-Goals:**
- No tocar la pestaña "Pendientes" (hoy tiene 0 filas; su patrón AJAX se conserva).
- No persistir telemetría de corridas AI-suggest (diferente change).
- No cambiar endpoints de bulk/approve/reject/destroy existentes.
- No reordenar/agrupar columnas más allá de lo necesario para paginar.

## Decisions

### 1. Paginación server-side vía query params en los endpoints existentes
`approved()` y `aiSuggestResults()` aceptan `page` (default 1), `per_page` (default 50, clamp 1..500), `search`, `source` y responden `{ items, total, page, last_page, sources }`.

- `approved`: `Correction::approved()->with(...)`, orden `applies_count DESC, id DESC` (se mantiene), luego filtros `search` (ILIKE sobre `wrong_text`/`correct_text`) y `source` (exacto), `paginate()`.
- `ai-suggest-results`: se conserva el resumen `runs` (últimas 5 corridas) con su shape actual; las listas `approved_list`/`pending_list` pasan a `approved_items`/`pending_items` paginadas + `approved_total`/`pending_total`. `source` filtra tanto runs como la lista de aprobadas/pendientes.

Alternativa descartada: paginar en cliente (slicing del array). No reduce el JSON transferido ni el tiempo de primera render — es el problema actual.

### 2. Shape de respuesta es `{ items, total, page, last_page, sources }`
Uniforme para ambas tablas. `sources` se calcula con `distinct(source)` sobre el mismo query ya filtrado por status/source-* (no sobre el diccionario entero), limitado a los orígenes que existan.

**BREAKING** para consumidores del shape viejo: `approved()` devuelve array plano; ahora objeto. Solo lo consume la vista Alpine (única caller), se migra junto.

### 3. Estado Alpine por tabla
- `approvedPage`, `approvedPerPage` (default 50), `approvedTotal`, `approvedLastPage`, `approvedSources`.
- `aiSuggest`: `approvedPage`, `approvedPerPage`, `approvedTotal`, `approvedLastPage`, `sources`.
- El dropdown de fuentes se puebla de `sources` del payload (no de `approved.length`).
- Filtros con debounce 300 ms → re-carga página 1.

### 4. Selección multi-página con tope 500
`approvedSelectedIds` ya es un `Set`. Se agrega:
- check-all = página visible (comportamiento actual, ya no puede seleccionar todo el diccionario por accidente).
- Botón "Seleccionar hasta 500": pide páginas sucesivas (`page=1..`) con los filtros activos, acumula ids hasta `bulk_max_ids=500`, o hasta agotar resultados.
- `approvedSelectedIds.size` alimenta la barra bulk existente; los bulk endpoints (`bulkDestroy`, excluir) no cambian.

### 5. Sin nueva tabla ni migración
La paginación usa Eloquent `paginate()` sobre índices existentes (`status`, `source` parcial). No se agregan columnas.

## Risks / Trade-offs

- [Breaking del shape JSON] → Único consumidor es la vista blade; se migra el estado Alpine en el mismo cambio. Sin callers externos.
- [Selección multi-página pesada al traer hasta 500 ids] → El botón hace requests paginados ligeros (50 por request) y acumula en cliente; no se re-renderiza la tabla.
- [Búsqueda case-insensitive en 2.467 filas] → `whereRaw('lower(wrong_text) like ?')` con clamp a índices; volumen pequeño, sin riesgo de full-scan perceptible.
- [Cambio de shape rompe el badge "X visibles / Y totales"] → Se recalcula de `approvedTotal` (total filtrado) en vez de `approved.length`.

## Migration Plan

- Deploy: cambios en controller + blade en el mismo commit. No requiere DB.
- Rollback: revertir el commit; el frontend nuevo asume el shape nuevo, así que revertir ambos juntos.
- No hay datos migrados ni backfills.

## Open Questions

Ninguna que bloquee. El default `per_page=50` y el tope de selección `500` ya vienen de `bulk_max_ids`; si se quiere selector de tamaño (50/100/200) se agrega después sin tocar specs (param ya diseñado).
