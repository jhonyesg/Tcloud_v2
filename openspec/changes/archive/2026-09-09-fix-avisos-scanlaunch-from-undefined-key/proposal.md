## Why

Al disparar un escaneo manual desde `/ia/avisos-inteligentes` eligiendo "Histórico completo (sin límite de fechas)" + "Forzar re-escaneo", el request POST `/ia/avisos-inteligentes/scan/run-bg` devuelve `500 Server Error` con el body genérico `{"message":"Server Error"}` y el modal pasa a `phase: 'error'` con el mensaje "No se pudo iniciar el escaneo". El operador no ve la causa real.

La causa raíz es `app/app/Http/Controllers/Ia/AvisosInteligentesController.php:416`:

```php
'window_label' => $validated['preset'] ?? ($validated['from'] || $validated['to'] ? 'custom' : 'global'),
```

Cuando el operador elige "Histórico completo", el body del request NO trae `from` ni `to`. El operador `??` protege solo a `preset`; el segundo operando accede a `$validated['from']` directamente y PHP 8.4 lanza `Undefined array key "from"` como ErrorException. El controlador no tiene try/catch alrededor de la lógica, así que la excepción burbujea al handler global y la respuesta es 500 genérico.

El problema es doble: (1) el bug puntual que rompe el flujo manual de catch-up, y (2) la falta de try/catch global del endpoint que convierte cualquier excepción futura en un "Server Error" opaco para el operador. Si arreglamos solo el bug, el próximo `Undefined array key` volverá a fallar silenciosamente y el operador volverá a llamar al admin.

## What Changes

- Fix mínimo: introducir variables intermedias `$preset`, `$from`, `$to` con `??` antes de la línea 416 para que el fallback no acceda a claves inexistentes. Cambio en bloque de 3-4 líneas.
- Fix defensivo: envolver toda la lógica de `runScanBackground()` en un try/catch que devuelva un JSON con `error` accionable (incluyendo archivo:línea del throw) y HTTP 500 explícito, en vez del 500 genérico de Laravel. Aplica a `runScanBackground` (POST) y a todos los demás endpoints del mismo controller que mutan estado via cache o worker (`runFullScan`, `rewindWatermark`, `saveAssign`, etc.).
- Auditoría exprés: un grep controller-side que liste todas las líneas que acceden a claves de `$request->` o `$validated` con `$arr['key']` sin `??` en el mismo archivo, para atacar otros casos con el mismo anti-patrón antes del merge. No es un auditor completo del codebase, solo una pasada por el controller afectado.
- Logging: el catch loguea con `Log::error()` incluyendo `exception`, `file`, `line`, `request` (body sanitizado), para que el operador vea en pantalla y el admin en logs sin grep manual.

## Capabilities

### New Capabilities
_Ninguna._

### Modified Capabilities
- `avisos-scan-configuration`: añadir un requisito sobre el comportamiento observable del endpoint: SHALL responder 5xx con un JSON que contenga `error` legible cuando una excepción interna rompe el flujo. SHALL loguear la traza completa en `Log::error` para diagnosticar.

## Impact

**Backend:**
- `app/app/Http/Controllers/Ia/AvisosInteligentesController.php`:
  - `runScanBackground()` (líneas ~372-485): try/catch envolvente + 3 variables intermedias con `??` para el cálculo de `window_label`.
  - `runFullScan()`, `rewindWatermark()` (preview), `saveAssign()` y otros endpoints que mutan estado: try/catch siguiendo el mismo patrón. Esto uniformiza la respuesta de error y previene futuras regresiones del mismo tipo.
- Sin migración de BD. Sin cambios en frontend. Sin cambios en workers supervisord. Sin cambios en rutas ni cache keys.
- El controlador queda +~30 líneas (helper `withErrorEnvelope()` o equivalente inline). Cambio backward-compatible: clientes que ya parseaban `error` siguen funcionando; clientes que no, reciben un mensaje más útil que el genérico actual.

**Frontend:**
- Sin cambios. El JS frontend ya consume `d.error` en `runScanBackground()` (`runScanBackground` usa `m.errors = [d.error || 'No se pudo iniciar el escaneo']`), así que automáticamente mostrará el nuevo mensaje legible.

**Tests:**
- Nuevo `tests/Feature/AvisosScanLaunchRegressionTest.php` que reproduce el bug: POST con `noWindow=true` y SIN `from`/`to` → espera 200 con `runId` (no 500). Bloquea la regresión para siempre.

**Operativo:**
- Riesgo bajo: el catch es defensivo y solo cambia la forma del 500 (a algo más útil). El flujo feliz no cambia.
- Backout simple: revertir el merge (no toca migración ni Redis, solo PHP-FPM).

## Non-goals

- No se cambia la lógica del modal frontend. Solo cambia el shape del 500 que llega al catch del frontend, que ya lo maneja.
- No se refactoriza el controller entero. Solo `runScanBackground` y los métodos hermanos más críticos.
- No se agrega una librería global de error handling (como Sentry, Bugsnag, etc.). Solo `Log::error()` con los campos relevantes.
- No se cambia el endpoint público, solo su manejo interno de excepciones.
- No se tocan los workers (`avisos:scan-run`), solo el controller que los lanza.
