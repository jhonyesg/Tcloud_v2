## Context

El 2026-09-09 reportaste que al disparar el escaneo desde "Escanear ahora" en `/ia/avisos-inteligentes` eligiendo "Histórico completo" + "Forzar re-escaneo" + click "Iniciar escaneo", el modal pasaba a `phase: 'error'` con "No se pudo iniciar el escaneo" sin más detalle. La traza de `storage/logs/laravel.log` apuntaba a `Undefined array key "from"` en `app/app/Http/Controllers/Ia/AvisosInteligentesController.php:416`. Reproducido con Playwright (credenciales `jsuarez`/`T3cn0l0g14`): el POST `/ia/avisos-inteligentes/scan/run-bg` devuelve 500 con body `{"message":"Server Error"}`.

El bug es doble: (1) el acceso directo a `$validated['from']` en una expresión compuesta, sin protección `??`, cuando el operador elige "Histórico completo" y el body del request excluye esa clave; (2) la ausencia de try/catch envolvente en el endpoint convierte cualquier futura excepción similar en 500 opaco. Si solo arreglamos (1), el próximo `Undefined array key` seguirá siendo invisible para el operador y volverá a llamar al admin.

El fix mínimo son 3-4 líneas (variables intermedias con `??`). El fix defensivo son ~30 líneas más (try/catch envolvente). Ambos van juntos en este change porque apuntan al mismo síntoma: errores opacos al operador.

## Goals / Non-Goals

**Goals:**
- Bug (1) resuelto: `$validated['from']` y `$validated['to']` accedidos sin protección, arreglado con extracción a variables locales antes del uso.
- Bug (2) resuelto: el endpoint `runScanBackground()` y los hermanos del mismo controller envueltos en try/catch con respuesta JSON legible + `Log::error()` con traza.
- Test de regresión que reproduce el escenario exacto del reporte (POST con `noWindow=true, force=true` sin `from`/`to`) y bloquea la reintroducción.
- Sin cambios en frontend, en workers, en cache, en BD, en rutas. Cambio backward-compatible.

**Non-Goals:**
- No se refactoriza el controlador entero.
- No se introduce una librería de error handling global.
- No se cambian los workers `avisos:scan-run`.
- No se cambian los flujos exitosos (latencia, formato de respuesta, código HTTP).

## Decisions

### 1. Variables intermedias con `??` en lugar de diff grande del one-liner

**Decisión**: extraer `$preset`, `$from`, `$to` ANTES de la línea de `window_label`, usando `?? null` en cada una. Después, el one-liner usa esas variables.

```php
$preset = $validated['preset'] ?? null;
$from   = $validated['from']   ?? null;
$to     = $validated['to']     ?? null;
...
'window_label' => $preset ?? ($from || $to ? 'custom' : 'global'),
```

**Rationale**: el one-liner original es legible para el reviewer, pero esconde el bug. Cualquier intento de "arreglarlo inline" repitiendo el `??` lo hace ilegible (`$validated['preset'] ?? (($validated['from'] ?? null) || ($validated['to'] ?? null) ? 'custom' : 'global')`). Las variables intermedias separan "normalización" de "lógica de negocio".

**Alternativa descartada**: usar `data_get()` o helper similar. Descartado porque introduce dependencia conceptual sin reducir líneas.

### 2. Try/catch envolvente con respuesta `{error, detail}` y `Log::error`

**Decisión**: helper privado `respondWithError(\Throwable $e, string $verb, Request $request)` que:
1. Loguea `Log::error("avisos.scan.<verb>_failed", ['exception' => $e, 'file' => $e->getFile(), 'line' => $e->getLine(), 'request' => $this->sanitizeRequest($request)])`
2. Devuelve `response()->json(['error' => "Error al $verb: " . $e->getMessage(), 'detail' => '...' ], 500)`

El frontend ya consume `d.error` y lo muestra al operador. La mejora es SOLO el contenido del mensaje: ahora describe el problema en vez del "Server Error" genérico.

**Rationale**: el operador necesita ver en pantalla QUÉ falló para no tener que ir a los logs de Laravel. Mostrar el `getMessage()` es suficiente para errores de aplicación; el `file:line` se queda en el log para el admin.

**Alternativa descartada**: propagar la traza al cliente (`Debug::display_exception`). Descartado porque filtraría paths internos (`/www/wwwroot/...`) que rompen prácticas de seguridad.

**Alternativa descartada**: usar `try/catch` solo en `runScanBackground`. Descartado porque deja al resto del controller con el mismo problema; si fallara otro método (e.g. `runFullScan`) seguiría siendo opaco.

### 3. Auditoría exprés grep-side antes del merge

**Decisión**: correr un grep rápido sobre `AvisosInteligentesController.php` (y sus vecinos) buscando `$validated['` y `$request->` sin `??` adyacente, listar hallazgos y atacar cada uno en este mismo change. No es un auditor completo (eso sería otro change).

**Rationale**: anti-patrón conocido. Un mismo bug en otra parte del archivo es plausible. Mejor cazarlo en este barrido que dejar la bomba de tiempo para la próxima vez.

**Alternativa descartada**: refactor sistemático (e.g. convertir todo a `data_get`). Descartado por costo vs beneficio; este change solo toca lo afectado directamente y deja nota en `AGENTS.md` para que el siguiente PR use `data_get` por convención.

### 4. Sin cambios en frontend, tests E2E con la fachada HTTP existente

**Decisión**: el frontend NO se modifica. `runScanBackground()` en `app/resources/views/ia/avisos-inteligentes/index.blade.php` línea ~798 ya hace `m.errors = [d.error || 'No se pudo iniciar el escaneo']`. Cuando el backend devuelva el nuevo `error` legible, automáticamente se mostrará en pantalla.

**Rationale**: separar frontend y backend en este caso evita acoplar el cambio a una release de UI. El cambio es enteramente backend.

**Alternativa descartada**: mejorar el modal con try/catch en el JS. Descartado porque el frontend ya hace lo correcto con datos mejores del backend.

## Risks / Trade-offs

- **[Mensaje de error podría exponer rutas internas]** → Mitigación: `Log::error()` loguea `file:line`; al cliente se le pasa solo `getMessage()` (sin `getFile()` ni `getTraceAsString()`). Si el `Message` de la excepción contiene paths (poco probable en errores capturados por Laravel), se puede sanitizar después.
- **[Try/catch podría enmascarar bugs reales]** → Mitigación: el catch hace `Log::error()` con la traza completa. El admin tiene la información; el operador ve el mensaje útil. Los bugs no desaparecen, solo se separan las audiencias.
- **[Latencia extra por try/catch en el camino feliz]** → Mitigación: negligible. PHP mide la latencia de try/catch en nanosegundos en el caso sin throw.
- **[El grep podría encontrar 0 líneas o 20]** → Mitigación: si son más de ~10, se separan en un follow-up change. Si son menos, se arreglan en este mismo PR.
- **[Compatibilidad con clientes existentes que esperan `message` clave]** → Mitigación: clientes existentes probablemente ni consumen la respuesta 5xx porque el caso exitoso es 200. Igual, se mantiene la clave `message` adicionalmente a `error` para no romper consumidores teóricos.

## Migration Plan

1. Merge del feature branch.
2. Reload de PHP-FPM (`nginx -s reload && systemctl reload php84-php-fpm`).
3. Sin migración de BD. Sin reinicio de workers supervisord. Sin cambios en Redis.
4. Verificación post-deploy:
   - Lanzar escaneo con "Histórico completo" + "Forzar" → debe transicionar a `phase: 'running'` con progreso real (no `phase: 'error'`).
   - Lanzar escaneo con preset "24h" → debe seguir funcionando idéntico al comportamiento anterior.
   - Simular otro error: Forzar un 500 artificial (e.g. endpoint con typo temporal) y confirmar que el operador ve un mensaje legible en el modal, no "Server Error" opaco.
5. Rollback: `git revert` + reload PHP-FPM. Riesgo mínimo.

## Open Questions

Ninguna para este change. La elección entre `data_get()` y variables intermedias se resolvió en Decisions §1. La elección entre catch inline vs helper se resolvió en §2 (helper privado, evita duplicación entre los ~5 endpoints del controller).
