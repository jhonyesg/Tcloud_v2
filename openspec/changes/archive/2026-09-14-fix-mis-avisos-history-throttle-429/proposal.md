# Propuesta: fix-mis-avisos-history-throttle-429

## Why

El endpoint `GET /mis-avisos/history` (pestaña Histórico de Mis Avisos) responde HTTP 429 con muy poco uso real del módulo: un operador que carga la página, abre el tab Histórico y aplica 3-4 filtros o atajos de fecha ya consume el 100% del cupo de **10 requests/minuto** configurado en `routes/web.php:443`. El throttle está calibrado como si fuera un endpoint de búsqueda pesada, cuando en realidad la UI dispara una llamada por cada cambio de filtro (per_page, atajo de fecha, toggle TV/Radio, click en "Buscar", checkbox de storage, deep-link desde URL con query params). Reproducido el 2026-09-15 con Playwright: solo 7 interacciones legítimas → 3 respuestas 429.

Además, cuando el 429 ocurre, la UI muestra el error genérico "Error al buscar" y la tabla queda vacía, sin contexto para el operador. La consola del navegador muestra `Failed to load resource: 429`, que el operador confunde con un bug del módulo.

## What Changes

- Subir el throttle de `throttle:10,1` a `throttle:30,1` en `routes/web.php:443` (alineado con el patrón de `GET /mis-avisos/feed` que ya usa `throttle:30,1`).
- Agregar un debounce de **250 ms** en `searchHistory()` de `mis-avisos/index.blade.php` para que cambios rápidos de filtros (per_page, atajos consecutivos) coalescan en una sola request.
- Cuando la UI recibe 429, mostrar un toast específico: "Demasiadas solicitudes en poco tiempo. Espera un momento y vuelve a buscar." en lugar del error genérico, manteniendo la tabla en su estado anterior (no la vacía).
- Sin cambios en el contrato HTTP, sin migración nueva, sin impacto en otros endpoints.

## Capabilities

### New Capabilities

(No hay capabilities nuevos — el comportamiento del throttle y la UX del 429 extienden uno existente.)

### Modified Capabilities

- `mentions-historical-export`: el requirement "La búsqueda está protegida contra sobrecarga del servidor" sigue vigente (sigue habiendo throttle y devolviendo 429 cuando se excede), pero se agrega:
  - Un scenario nuevo describiendo que **interacción normal del UI NO debe disparar 429** (debounce + throttle razonable).
  - Un scenario nuevo describiendo el **mensaje específico al usuario** cuando el 429 ocurre.

## Impact

- **Ruta afectada**: `routes/web.php:443` (1 línea: `throttle:10,1` → `throttle:30,1`).
- **Vista afectada**: `app/resources/views/mis-avisos/index.blade.php` (función `searchHistory` + manejo del branch `res.ok = false`).
- **Sin migración**: el throttle vive en `app/routes/web.php`, no en BD.
- **Sin impacto en otros endpoints**: el throttle es específico por ruta + session.
- **Sin riesgo de seguridad**: 30 req/min sigue siendo protección efectiva contra scraping masivo (300 req/hora por sesión, comparable al feed).

## Non-goals

- No se introduce cache Redis del endpoint (sería un change separado tipo `2026-09-13-perf-audit-and-improve`). Esta propuesta solo ajusta el throttle y la UX.
- No se cambia el endpoint `/mis-avisos/feed` (ya tiene throttle 30/min razonable).
- No se cambia el throttle de exports (`throttle:6,1`) ni de email (`throttle:4,1`) — esos siguen calibrados correctamente.
- No se modifica la lógica de `hydrateHistoryFromUrl` ni `syncHistoryUrl` — solo se reduce la frecuencia de llamadas, no se cambia el flujo.
