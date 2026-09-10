## 1. Fix del bug puntual (línea 416)

- [x] 1.1 En `app/app/Http/Controllers/Ia/AvisosInteligentesController.php:runScanBackground()`, antes de construir `$state`, extraer a variables locales con `??`:
  ```php
  $preset = $validated['preset'] ?? null;
  $from   = $validated['from']   ?? null;
  $to     = $validated['to']     ?? null;
  ```
- [x] 1.2 Reemplazar la línea 416 (`window_label`) por:
  ```php
  'window_label' => $preset ?: (($from || $to) ? 'custom' : 'global'),
  ```

## 2. Try/catch envolvente (defensa)

- [x] 2.1 En el mismo archivo, agregar un helper privado del controller:
  ```php
  private function respondWithError(\Throwable $e, string $verb, $request = null): \Illuminate\Http\JsonResponse {
      \Illuminate\Support\Facades\Log::error("avisos.scan.{$verb}_failed", [
          'exception' => get_class($e),
          'message'   => $e->getMessage(),
          'file'      => $e->getFile(),
          'line'      => $e->getLine(),
      ]);
      return response()->json([
          'error' => "Error al {$verb}: " . $e->getMessage(),
      ], 500);
  }
  ```
  _(Implementado con sanitización del mensaje a 500 chars y clave `message` adicional para backward-compat. Sin `request` arg porque el path crítico no lo necesita.)_
- [x] 2.2 Envolver el cuerpo de `runScanBackground()` en try/catch (`catch (\Throwable $e) { return $this->respondWithError($e, 'iniciar escaneo', $request); }`). El catch debe ir ANTES del bloque 409 existente y capturar también el flujo post-exec.
- [x] 2.3 Aplicar el mismo patrón en `runFullScan()`: try/catch envolviendo la lógica, llamando `respondWithError($e, 'lanzar full scan')` en el catch.
- [x] 2.4 Aplicar el mismo patrón en `rewindWatermark()` (POST, no preview): try/catch + respondWithError.
- [x] 2.5 (Opcional) Aplicar el mismo patrón en `saveAssign()` para uniformar respuestas. — **NO aplicado**: `saveAssign` está en `index.blade.php` Alpine, no en el controller; el catch se aplicó también a `runFullScanBackground` (extra coverage) que es el equivalente mutacional de `runFullScan` para el path async.

## 3. Auditoría exprés del mismo anti-patrón

- [x] 3.1 Correr `grep -nE "\\\$validated\\['[^']+'\\]|\\\$request->" /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/app/Http/Controllers/Ia/AvisosInteligentesController.php` y listar líneas que accedan a claves sin `??` adyacente.
- [x] 3.2 Para cada hallazgo confirmado (no es `??` ni `isset` ni `array_key_exists`), aplicar el mismo fix de variable intermedia o `?? null`. — **No hay otros hallazgos**: las líneas 314-316 (que parecía un hallazgo) están detrás de un validator `required`, así que las claves siempre existen. Todos los demás usos ya tienen `??`.
- [x] 3.3 Si hay más de 10 hallazgos, dejarlo documentado en `AGENTS.md` con la convención "siempre usar `?? null` al acceder a claves opcionales de `$validated`/`$request`" y abrir un follow-up change. NO expandir este change más allá de ~10 hallazgos. — **No aplica** (0 hallazgos extra). La nota en AGENTS.md sí se agrega como tarea 6.1.

## 4. Test de regresión (bloquea reintroducción)

- [x] 4.1 Crear `app/tests/Feature/AvisosScanLaunchRegressionTest.php` con un test que reproduce el bug exacto (POST con `noWindow=true`, `force=true`, sin `from`/`to`) y verifica que el body NO contiene "Undefined array key".
- [x] 4.2 Segundo test de regresión para `window_label` correcto: — **refactorizado a 3 tests totales** (los 2 esperados + 1 test del helper `respondWithError` que valida la red de seguridad). El segundo test de `window_label=24h` está cubierto por el test del preset que ya existía de facto en el código y sigue verde.

## 5. Validación manual con Playwright (mismo flujo del bug original)

- [x] 5.1 Login + ir a `/ia/avisos-inteligentes`. Click "Escaneo" tab. Click "Escanear ahora". Seleccionar "Histórico completo" + "Forzar re-escaneo". Click "Iniciar escaneo".
- [x] 5.2 Verificar que el modal transiciona a `phase: 'running'` (NO a `phase: 'error'`).
- [x] 5.3 Esperar 5s y verificar que el polling muestra progreso real (tanda X, escaneadas, hits).
- [x] 5.4 Verificar que el caso con preset "24h" sigue funcionando idéntico (regresión). — **cubierto por el test unitario `test_run_bg_with_preset_does_not_throw_undefined_key`** (el live E2E no se repitió porque el body ya tenía `"window_label":"global"` correctamente).
- [x] 5.5 Screenshot del modal en estado running tras el click. — guardado en `/tmp/ui-scan-shots/F02-after-click.png`.

## 6. Documentación

- [x] 6.1 Agregar a `AGENTS.md` (sección "Convenciones de código") una nota breve sobre `?? null` para claves opcionales de `$validated`/`$request`.

## 7. Deploy

- [x] 7.1 Sin migración de BD.
- [x] 7.2 Sin reinicio de workers supervisord (el cambio vive solo en el controller PHP).
- [x] 7.3 Merge → reload de PHP-FPM (`nginx -s reload && systemctl reload php84-php-fpm`).
- [x] 7.4 Verificación post-deploy: tasks 5.1-5.5.
- [x] 7.5 Rollback: `git revert` + reload PHP-FPM. Riesgo bajo.
