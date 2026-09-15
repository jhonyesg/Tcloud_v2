# Tasks: fix-mis-avisos-history-throttle-429

## 1. Backend: ajuste del throttle

- [x] 1.1 En `app/routes/web.php` línea 443, cambiar `->middleware('throttle:10,1')` por `->middleware('throttle:30,1')` para alinear con `/mis-avisos/feed`.
- [x] 1.2 Verificar con `grep "throttle:" app/routes/web.php | grep -E "mis-avisos/(history|feed)"` que ambos endpoints muestran `30,1` (consistencia).
- [x] 1.3 Ejecutar `cd app && php artisan route:list --path=mis-avisos/history` y confirmar que el middleware de throttle quedó aplicado.

## 2. Frontend: debounce en `searchHistory`

- [x] 2.1 En `app/resources/views/mis-avisos/index.blade.php` (~línea 757), agregar `searchHistoryDebounce: null` al state del componente Alpine `misAvisosPage()`.
- [x] 2.2 Refactorizar `searchHistory(page)` (~línea 1310) para envolver el cuerpo en un `setTimeout(..., 250)` y agregar parámetro opcional `immediate = false` que hace bypass del debounce.
- [x] 2.3 En `hydrateHistoryFromUrl()` (~línea 1359), cambiar la llamada a `this.searchHistory(1)` por `this.searchHistory(1, { immediate: true })` para que la carga inicial desde URL con deep-link no sufra el debounce.
- [x] 2.4 Verificar que el input de búsqueda libre (`@keydown.enter="searchHistory(1)"` ~línea 440) sigue funcionando con el debounce — el Enter es explícito así que el delay de 250ms es aceptable (NO requiere bypass).

## 3. Frontend: UX del 429

- [x] 3.1 En la rama `res.ok === false` de `searchHistory` (~línea 1332), separar el caso `res.status === 429` del genérico:
  - 429 → `this.pushToast('Demasiadas solicitudes en poco tiempo. Espera un momento y vuelve a buscar.', 'warning', 4500)` y **NO** vaciar `historyRows`.
  - Otros errores → comportamiento actual (`this.historyError = d.error || 'Error al buscar'; this.historyRows = []`).
- [x] 3.2 Confirmar que `pushToast` está disponible en el componente (ya existe en línea 815-820). Si por alguna razón el método no existiera, agregarlo con la firma `pushToast(text, kind, ttlMs)`.

## 4. Verificación con Playwright

- [x] 4.1 Re-ejecutar `python3 tests/diag_mis_avisos_429.py` y verificar en el JSON resultante:
  - `total_history_calls >= 7` (mismo flujo que antes)
  - `history_status_counts` solo contiene `200` (cero 429s)
  - `console_errors` está vacío (los 429 ya no deben aparecer en consola)
- [x] 4.2 Re-ejecutar `python3 tests/diag_mis_avisos_429_interaction.py` y verificar lo mismo.
- [x] 4.3 Capturar screenshot del toast "Demasiadas solicitudes..." forzando un 429 manual (curl `for i in {1..35}; do curl -s -o /dev/null -w "%{http_code}\n" -b cookies.txt https://cloud.mediaserver.com.co/mis-avisos/history; done` debe mostrar 30 × 200 y 5 × 429).

## 5. Revisión final

- [x] 5.1 `git diff app/routes/web.php app/resources/views/mis-avisos/index.blade.php` y revisar el diff completo (debe ser <30 líneas en total).
- [x] 5.2 Confirmar que NO hay cambios en otros archivos del módulo (controller, service, modelo).
- [x] 5.3 Commit con mensaje `fix(mis-avisos): subir throttle de /history a 30/min + debounce 250ms + toast 429 (fix #429)`.
- [x] 5.4 Después del merge, ejecutar `php artisan config:cache` y `systemctl reload php84-php-fpm` para que el opcode cache libere la nueva versión de `routes/web.php`.
