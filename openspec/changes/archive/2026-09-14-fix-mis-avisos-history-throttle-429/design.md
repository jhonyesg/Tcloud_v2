# Design: fix-mis-avisos-history-throttle-429

## Context

El endpoint `GET /mis-avisos/history` (`app/routes/web.php:443`) tiene un throttle de **10 req/min** calibrado conservadoramente desde su creación original. La UI del tab Histórico de Mis Avisos (`app/resources/views/mis-avisos/index.blade.php`) dispara una llamada a este endpoint en cada interacción del cliente: abrir el tab, aplicar un atajo de fecha, cambiar `per_page`, alternar TV/Radio, hacer click en "Buscar", cambiar checkboxes de storage, y al cargar la página si la URL trae `?date_field=program` (deep-link). Reproducido con Playwright: 7 interacciones legítimas en <60s → 3 respuestas 429.

El endpoint hermano `/mis-avisos/feed` ya usa `throttle:30,1` (`app/routes/web.php:421`) — alinearse con él es la calibración natural. No hay cache Redis de este endpoint todavía (sería otro change tipo `2026-09-13-perf-audit-and-improve`); esta propuesta solo ajusta el throttle y la UX del 429.

## Goals / Non-Goals

**Goals:**
- Subir el throttle a `30,1` (alineado con `/mis-avisos/feed`).
- Agregar debounce de 250 ms en `searchHistory()` para coalescar clics rápidos de atajos/filtros.
- Mostrar un toast específico al usuario cuando el 429 ocurre, preservando el contenido actual de la tabla.
- Mantener el patrón existente de `pushToast()` ya presente en el componente Alpine (`misAvisosPage()`).

**Non-Goals:**
- No introducir cache Redis del endpoint (cambio separado si la monitorización lo justifica).
- No modificar el throttle de los endpoints de export (`throttle:6,1` y `throttle:4,1`) ni del feed (`throttle:30,1` ya existente).
- No tocar la lógica de `hydrateHistoryFromUrl` ni `syncHistoryUrl` — solo se reduce la frecuencia, no se cambia el flujo.
- No agregar un endpoint nuevo, no cambiar el contrato HTTP, no migrar BD.

## Decisions

### Decision 1: throttle `30,1` en lugar de `60,1` o `100,1`

**Por qué 30**: alineado con `/mis-avisos/feed` (mismo dominio semántico, mismo cliente, mismo patrón de UI). 30 req/min × 60 min = 1800 req/hora por sesión, suficiente para uso intensivo de un operador y aún seguro contra scraping.

**Por qué no más alto**: si el operador dispara 60 req/min legítimamente, hay un bug de UI que el throttle más alto solo enmascararía. Con 30/min + debounce 250ms, ese hipotético bug seguiría siendo visible sin romper UX normal.

**Por qué no más bajo**: el spec nuevo "Interacción normal del UI de Histórico NO dispara 429" exige que un flujo completo (9-10 interacciones) en 60s sea 200 — eso requiere ≥30 req/min garantizados.

**Alternativa considerada**: cachear el endpoint en Redis con TTL 15s (`avisos:history:results:{user}:{hash(filtros)}`). **Descartada** para esta propuesta porque (a) introduce invalidación por mutaciones en `segment_keyword_hits` no triviales, (b) sería un change más grande. Se deja como follow-up si después del fix la latencia del endpoint lo justifica.

### Decision 2: debounce 250 ms implementado como wrapper interno de `searchHistory`

**Por qué wrapper interno** y no un `$watch` reactivo: un `$watch` sobre `historyFilters` dispararía también en `hydrateHistoryFromUrl` (carga inicial con URL params), lo que duplicaría la llamada inicial (1 del `hydrateHistoryFromUrl` + 1 del watch). El wrapper conserva el patrón actual: solo se ejecuta `searchHistory` cuando el código lo pide explícitamente.

**Por qué 250 ms** y no 150 / 500: 250 ms es el sweet spot estándar para interacciones de filtrado — lo suficiente para coalescar doble/triple clic accidental, lo bastante corto para que el usuario no perciba lag. El input de búsqueda libre se mantiene en `@keydown.enter` (sin debounce) porque ahí el usuario da Enter explícitamente.

**Implementación propuesta**:

```js
// Antes (index.blade.php ~1310):
async searchHistory(page) {
    this.historyError = '';
    // ... construye params ...
    const res = await apiFetch(...);
}

// Después:
_searchHistoryDebounceTimer: null,
async searchHistory(page) {
    this.historyError = '';
    clearTimeout(this._searchHistoryDebounceTimer);
    this._searchHistoryDebounceTimer = setTimeout(async () => {
        // ... construye params ...
        const res = await apiFetch(...);
        if (res.ok) { ... }
        else if (res.status === 429) {
            this.historyError = '';  // no reemplazar tabla con error
            this.pushToast('Demasiadas solicitudes en poco tiempo. Espera un momento y vuelve a buscar.', 'warning', 4500);
        }
        else {
            const d = await res.json();
            this.historyError = d.error || 'Error al buscar';
        }
    }, 250);
}
```

**Excepción**: `hydrateHistoryFromUrl` debe llamar al backend **sin debounce** (es la carga inicial tras deep-link, el usuario espera el resultado). Se introduce un parámetro `searchHistory(page, { immediate: false })` para que esa ruta haga bypass. Default `immediate: false` para todos los callers actuales.

### Decision 3: toast específico para 429 sin vaciar la tabla

**Por qué toast y no `historyError`**: `historyError` es un mensaje visible arriba de la tabla (línea 757) que actualmente reemplaza el contenido con un texto rojo. Eso confunde al operador: si tenía 50 filas cargadas y aplica un filtro, ve "Error al buscar" + tabla vacía, y cree que el filtro "rompió" la búsqueda. Un toast efímero (parte superior derecha) comunica el 429 sin destruir el contexto.

**Por qué preservar `historyRows`**: la spec nueva "429 entrega mensaje específico al usuario" lo exige explícitamente. La rama `res.ok === false` actual hace `this.historyRows = []` (línea 1335); cambiamos ese branch para que solo asigne `historyError` en errores no-429. Para 429 no tocamos `historyRows`.

**Por qué `pushToast` y no `alert()`**: el componente ya tiene el patrón `pushToast(text, kind, ttl)` (líneas 815-820 del index.blade.php). Usar `alert()` reintroduciría el problema UX de los toasts no-destructivos que el módulo ya migró.

### Decision 4: NO cachear `MentionsSearchService::searchHistory()` en este change

El endpoint ejecuta queries acotadas por índice (búsqueda por storage + rango 60d) pero igual hace JOINs con `segment_keyword_hits`. La latencia warm observada (con datos reales del cliente jsuarez) es ~80-200 ms — aceptable. Cachear agregaría complejidad de invalidación por cada nueva mención o cambio de transcripción. **Se descarta cache** en este change; queda como follow-up si el endpoint se vuelve lento con más storages o más histórico.

## Risks / Trade-offs

- **[Risk] Subir el throttle podría permitir scraping ligero del histórico.** → Mitigation: 30/min sigue siendo 1 req cada 2 segundos sostenido — no es viable para scraping real. Si en el futuro se observa abuso, agregar throttle por IP además de por sesión.

- **[Risk] El debounce de 250 ms agrega latencia perceptible al filtro intencional.** → Mitigation: 250 ms es ¼ de segundo — por debajo del umbral de percepción de lag para una acción de filtrado. El usuario hace click → espera respuesta → 250 ms es invisible.

- **[Risk] El bypass `immediate:true` en `hydrateHistoryFromUrl` puede ser olvidado por callers futuros.** → Mitigation: agregar comentario JSDoc `@param {boolean} immediate` y test unitario que verifique que con `immediate:true` NO hay `setTimeout`.

- **[Risk] El toast del 429 podría dispararse en masa si hay un bug que dispare 30 calls/segundo.** → Mitigation: el throttle ya devuelve 429 antes de que el backend sufra; el toast es client-side y no agrega carga. Si fuera spam, el patrón actual del componente tiene `dismissToast` automático por TTL.

- **[Risk] Otros módulos que llamen `searchHistory()` (¿existen?) se verían afectados por el debounce.** → Búsqueda en el repo: `searchHistory(` solo se invoca dentro del mismo `index.blade.php` (12 matches, todos internos). No hay callers externos. **Sin riesgo.**

## Migration Plan

No hay migración de BD. No hay workers que reiniciar. El flujo de deploy es:

1. `git revert <hash>` si se quiere rollback inmediato.
2. `systemctl reload php84-php-fpm` para liberar opcode cache de `routes/web.php` y del Blade.
3. Sin acción sobre Redis (no se introducen keys nuevas).
4. Sin acción sobre supervisord (no se tocan workers).

**Freno de emergencia sin deploy**: si tras desplegar se observa que el throttle `30,1` sigue siendo insuficiente, se puede subir temporalmente vía cambio de la ruta (1 línea en `web.php`) o agregando un override en `.env` (más invasivo — requiere `php artisan config:cache`).

**Verificación post-deploy**:
- `grep "throttle:" app/routes/web.php | grep history` → debe mostrar `throttle:30,1`.
- Smoke con Playwright (mismo script de diagnóstico): ejecutar 30 requests en 60s → todas 200; la #31 → 429 con toast visible.

## Open Questions

Ninguna. La calibración (30/min + 250ms debounce + toast específico) cubre los escenarios del spec sin ambigüedades. Si en producción se observa que aún hay 429 con uso legítimo, el siguiente paso natural sería el cache Redis (Decision 1, alternativa descartada) — pero es un change separado que se puede decidir con datos reales de carga.
