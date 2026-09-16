## 1. Localización del bug

- [x] 1.1 Confirmar (HTTP-based + render programático) que el modal "Archivos" del storage y **TODOS** los demás modales quedaban fuera del scope `apiTranscriptor`. **Resultado:** el bug es estructural y de doble cascada — un `</div>` **temprano** en el toolbar del storages tab cerraba el header bar antes de que terminaran sus hijos (search bar / select / count), luego dos `</div>` posteriores cerraban storages tab y wrapper antes de que abriera el modal `showFiles`. Por balance del stack, eso arrastraba al wrapper completo, dejando **todos los demás modales** (`showProgress`, `storageToDisable`, `showProcessConfirm`, `showBatchModal`, `transcript.open`) como hermanos del wrapper, no hijos. Por eso `transcript.data?.duration_seconds`, `formatDuration(...)` y los otros bindings del transcript viewer lanzaban `ReferenceError: transcript is not defined` aunque el modal nunca se abriera — Alpine evalúa `x-show="transcript.open"` aunque devuelva `false`.

## 2. Aislamiento de la regresión HTML5

- [x] 2.1 Renderizar el HTML del view con PHP/Laravel (`view()->render()` con `session('user_id')=1`) y parsear el DOM con un parser stack-based sobre `/<div\b[^>]*>|<\/div>/`.
- [x] 2.2 **Causa raíz identificada (en orden)**: (a) toolbar del storages tab tiene un `</div>` que cierra el header bar antes de que terminen sus hijos; (b) consecuencia: dos `</div>` posteriores cierran storages tab y wrapper antes del modal `showFiles`; (c) consecuencia: TODOS los modales quedan fuera del wrapper.
- [x] 2.3 Bug adicional: la ruta `GET /ia/api-transcriptor/live-consumption` referenciada por el panel "Consumo" no estaba registrada en `routes/web.php` (404 silencioso → JSON parse error en `fetch` → `SyntaxError: Unexpected token '<'`).

## 3. Aplicar el fix

- [x] 3.1 **Fix 1 (Blade)**: en `app/resources/views/ia/api-transcriptor/index.blade.php`, eliminado el `</div>` redundante del header bar de storages que cerraba antes de que el search bar, el select de filtro y el contador pudieran renderizarse como hijos del header bar. Ahora los 6 modales están dentro del wrapper (verify: en el rendered HTML, showFiles a L1095, transcript a L2586, wrapper cierra a L2712).
- [x] 3.2 **Fix 2 (rutas)**: añadida la ruta `GET /ia/api-transcriptor/live-consumption → liveConsumption` en `app/routes/web.php`. Sin esto el panel "Consumo" hacía un `fetch()` que devolvía HTML 404 (porque Laravel responde con la página de login al 404) y el JS fallaba con `SyntaxError: Unexpected token '<'`.
- [x] 3.3 Verificar visualmente (vía render): banner "carpetas sin archivos", tabla storages, los 4 tabs (storages/jobs/consumo/config) y los 6 modales renderizan sin warnings de PHP/Laravel. Render = 365KB sin excepciones.

## 4. Regression test

- [x] 4.1 `tests/harness_api_transcriptor_modal_scope.php` — extendido a **10 aserciones**:
  1. `<div x-data="apiTranscriptor(...)">` presente
  2. `<div x-cloak x-show="showFiles">` presente
  3. Modal `showFiles` anidado dentro del wrapper (balance stack >= 1) — **balance=2**
  4. Modal `showFiles` anidado dentro del tab storages (balance stack >= 1) — **balance=1**
  5. Bindings Alpine presentes y bien formados: `currentStorage?.name`, `filesMode === 'browse'`, `filesSearch`, `setMode('browse')`
  6. **Cierre**: el `</div>` del modal ocurre antes que el `</div>` del wrapper — modal=100251 < wrapper=194050
  7. Definición `function apiTranscriptor(config = {})` presente en `@push('scripts')`
  8. Texto "Escanear storages" o `scanStorages()` presente
  9. **Los 6 modales** están dentro del wrapper (`showFiles`, `showProgress`, `storageToDisable`, `showProcessConfirm`, `showBatchModal`, `transcript.open`) — esta aserción atrapa el bug del transcript viewer
  10. Ruta `GET /ia/api-transcriptor/live-consumption` registrada en el router — esta aserción atrapa el 404 del panel Consumo
- [x] 4.2 Harness ejecutado: `php tests/harness_api_transcriptor_modal_scope.php` → **✅ PASS (0/10 failures)**.

## 5. Despliegue

- [x] 5.1 `php artisan view:clear` ejecutado desde `app/` — `INFO Compiled views cleared successfully.`
- [x] 5.2 Validar HTML renderizado (vía `view()->render()` con `session('user_id')=1`): los 6 modales están anidados dentro del wrapper, **NO como hermanos**. Confirmado por harness Aserciones 3, 4, 6, 9.
- [x] 5.3 php-fpm NO requiere reload (cambian Blade + routes/web.php; opcode cache se renueva con el siguiente request).
- [ ] 5.4 Validación visual en navegador real (Chrome/Edge con login `jsuarez` / `T3cn0l0g14`). **Pendiente para el operador.** El harness cubre los aspectos estructurales + bindings + ruta. La verificación final de consola (sin warnings `Alpine Expression Error` y sin `SyntaxError: Unexpected token '<'`) la tiene que hacer el operador manualmente.

## Notas de implementación

- **Doble fix fue necesario**: un solo `</div>` removido NO era suficiente porque el bug tenía dos manifestaciones. La fix inicial (encontrada en una sesión anterior) solo arreglaba el modal `showFiles` (un fix parcial que pasaba 8/8 aserciones de un harness anterior), pero dejaba los otros 5 modales fuera del wrapper. El fix completo requerido eliminar el `</div>` del header bar para mantener el wrapper vivo hasta después del transcript modal.
- **Asimilación de errores adicionales**: el operador reportó `transcript is not defined` y `formatDuration is not defined` en consola. La causa NO era lógica JS sino estructural (los bindings buscaban `transcript` en el scope equivocado). Con la fix 1 todos esos errores desaparecen automáticamente porque Alpine ahora encuentra `transcript` en el wrapper.
- **Endpoint live-consumption**: el método `liveConsumption()` ya existía en el controller pero la ruta no estaba cableada en `routes/web.php`. Agregada siguiendo el patrón de `/api-transcriptor/health`, `/api-transcriptor/stats`, etc.
- **Sesión Redis pre-existente (NO parte de este change)**: al intentar validar con `curl --resolve cloud.mediaserver.com.co:80:127.0.0.1`, la cookie se establece correctamente al hacer login, pero el siguiente request a `/ia/api-transcriptor` rebota a `/login`. Esto es consistente con el bug de sesiones Redis documentado en `AGENTS.md` §"Regla crítica: sesiones en Redis" (DB lógica 2, conexión `session`). El render programático con `session()->put()` sí funciona — usado por el harness.
