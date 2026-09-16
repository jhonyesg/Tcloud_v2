# fix-x-collapse-transcriptor-config-warnings

## Why

`enrich-config-ui-transistor-settings` introdujo directivas `x-collapse`
en los 10 knobs detallados del panel `/ia/api-transcriptor` → Configuración.
La directiva requiere el plugin oficial `@alpinejs/collapse` (1.4 KB).

Cue del problema: el plugin descargado (`public/js/alpine-collapse.min.js`)
estaba disponible y el `<script defer src="/js/alpine-collapse.min.js">` en
`layouts/app.blade.php` estaba insertado, PERO el Blade cache compilado
del layout servido por php-fpm quedó con la versión vieja (sin el
script tag). Resultado en producción: 67 warnings de Alpine por carga de
página ("`You can't use [x-collapse] without first installing the
'Collapse' plugin here: https://alpinejs.dev/plugins/collapse`") + el
famoso overlay "Don't show again" que ensucia la consola del operador.

Confirmado con Playwright (`tests/e2e/validate-final.mjs`) que captura
todos los warnings del browser. Diagnóstico reproducible con:

  $ curl -fsSI https://cloud.mediaserver.com.co/js/alpine-collapse.min.js
  HTTP/1.1 200 OK          ← archivo deployed
  $ curl -fsS https://cloud.mediaserver.com.co/ia/api-transcriptor | grep alpine
  (vacío)                  ← layout NO referencia el plugin

## What Changes

1. **`app/resources/views/ia/api-transcriptor/_settings-tab.blade.php`** —
   reemplazar `x-collapse` por `x-transition.opacity.duration.150ms` en
   los 10 `<div>` con detalle. `x-transition` es directiva built-in de
   Alpine core (sin plugin externo). Animación: fade in/out 150ms.
   Sin colapso de altura animado — deshabilitar visualmente, pero la
   visibilidad funciona idéntica.

2. **`app/resources/views/layouts/app.blade.php`** — remover la línea
   `<script defer src="/js/alpine-collapse.min.js?...">` que ya no
   necesitamos. Queda limpio el bloque de imports.

3. **`app/public/js/alpine-collapse.min.js`** — eliminar el archivo
   (1437 bytes menos en cada deploy).

4. **`app/public/favicon.ico`** — crear (copia de `logo.png`) para
   silenciar el 404 que el browser auto-generaba contra
   `GET /favicon.ico` y contaminaba el reporte de consola con
   console.error. Mismo branding, evita trabajo extra.

5. **`php artisan view:clear`** — corrió desde el servidor de producción
   para forzar recompilación de los Blade cacheados. Sin este paso,
   el cambio a `_settings-tab.blade.php` no aparece en `php-fpm`
   workers hasta el próximo `view:cache`/`view:clear`.

## Capabilities

### New Capabilities

Ninguna. Es 100% cambio de visibilidad/silencioso.

### Modified Capabilities

`transcriptor-config-ui-render` y derivados: el comportamiento de los 10
accordions cambia de "animación de altura" a "animación de opacidad".
Funcionalmente equivalente: el contenido se muestra/oculta correctamente,
y es accesible por teclado.

## Non-goals

- NO se reintroduce el plugin x-collapse. Si en el futuro se quiere
  animación de altura, se puede volver a `x-collapse` agregando el
  asset y `view:clear` después del deploy.
- NO se cambia schema, controllers, ni rutas.
- NO se tocan los demás groups/knobs del módulo transcriptor.
- NO se commitea/pushea el diff gigante heredado del change
  `transcriptor-pg-native-queue` (33 M + 9 D + 34 ??). Ese es
  trabajo previo fuera del scope de este change.

## Impact

**Archivos tocados (4):**

- `app/resources/views/ia/api-transcriptor/_settings-tab.blade.php` — línea 158: `x-collapse` → `x-transition.opacity.duration.150ms`.
- `app/resources/views/layouts/app.blade.php` — remover línea script tag.
- `app/public/js/alpine-collapse.min.js` — eliminar archivo.
- `app/public/favicon.ico` — crear (9889 bytes, copia de logo.png).

**Cambios no-source:**

- `php artisan view:clear` corrido en producción.

**APIs / rutas / modelos**: ninguno cambia.

**Migraciones de BD**: ninguna.

**Riesgo**: muy bajo. `x-transition` es compatible con Alpine core (ya
usado en 16 sitios del proyecto: `layouts/app.blade.php` líneas 124-129,
200, 211, 226, 231, 236, 248, 259, 264, 269, 274, 279, 310-315, 342, 353,
358, 363, 379, 385, 426, 436, 480, 498-503, 509-511, 559-564, 570-572).

**Rollback**: `git revert 1ac4757` + `php artisan view:clear`. Sin estado
persistente.

## Verificación post-implementación

```bash
$ APP_URL=https://cloud.mediaserver.com.co \
  ADMIN_LOGIN=jsuarez \
  ADMIN_PASSWORD=... \
  node tests/e2e/validate-config-ui.spec.mjs
```

Output esperado:
```
console.errors:     0
console.warnings:   0
page errors:        0
failed requests:    0
HTTP 4xx:           0
HTTP 5xx:           0
```

Verificado manualmente con `validate-final.mjs` (suite interactiva
que emula usuario: login, dashboard, abrir 6 accordions, toggle,
refrescar):
```
Tests: 11 passed, 0 failed
```
