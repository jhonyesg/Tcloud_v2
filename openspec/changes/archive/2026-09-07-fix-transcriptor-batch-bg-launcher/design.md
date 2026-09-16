## Context

Ver `proposal.md` (Why) para la motivación. El estado actual es:

- `RunsBackgroundCommands::execBackground(string $cmd, string $logTag)` envuelve `$cmd` en `setsid bash -c "echo start; $cmd; echo end >> /tmp/kilo_artisan_bg.log 2>&1" &`. Asume que el caller le pasa un comando puro sin redirección ni `&` final.
- Tres callers usan el trait. Dos (`CorreccionesController::apply`, `AvisosInteligentesController::scan`) respetan el contrato y funcionan. El tercero (`ApiTranscriptorController::processBatch`, líneas 1098-1111) lo viola: le pasa un `$cmd` que ya termina con ` >> $logFile 2>&1 &`. Cuando el trait lo envuelve, queda `<cmd-con-&>; echo ...` que es bash inválido (`&` ya terminó la lista, así que el `;` que viene después es syntax error). El subshell aborta sin ejecutar nada, el artisan nunca arranca, y el cache `transcription_batch:{runId}` se queda en `status: starting` durante el TTL de 2h.

El fix es una corrección de contrato (el trait centraliza redirección + backgrounding, los callers solo pasan el comando artisan puro) más una salvaguarda (el trait detecta el patrón viejo y lo normaliza) más una red de seguridad para que el modal no se quede mudo si algo del wrapper falla.

## Goals / Non-Goals

**Goals:**
- Eliminar la ambigüedad de contrato entre `RunsBackgroundCommands` y sus callers: uno solo de los dos maneja la redirección y el `&`.
- Hacer el bug imposible de reintroducir: si un caller nuevo (o un refactor que revive el patrón viejo) le pasa un `$cmd` con `&` o redirección, el trait lo normaliza silenciosamente en lugar de fallar silenciosamente.
- Que el cache `transcription_batch:{runId}` refleje el estado real del proceso aún cuando el wrapper falla: `status: error` con mensaje accionable en vez de quedarse pegado en `starting`.

**Non-Goals:**
- No se cambia el mecanismo de aislamiento (`setsid bash -c`). Solo se ajusta lo que se le pasa adentro.
- No se rediseña el modal frontend ni los timeouts de polling.
- No se cambia el comando `transcription:scan-and-submit` ni su lógica de fases.

## Decisions

### 1. El trait centraliza redirección + backgrounding; los callers pasan comandos puros

**Decisión**: `RunsBackgroundCommands::execBackground(string $cmd, string $logTag = 'unknown', ?string $logFile = null)`. Cuando `$logFile` no es null, el trait agrega `>> $logFile 2>&1` antes del `&` final. El trait SIEMPRE agrega el `&` final.

**Rationale**: Centralizar evita la ambigüedad que produjo el bug. Los callers solo construyen `php artisan comando --flag=valor` y nada más.

**Alternativa descartada**: dejar que cada caller siga manejando su propia redirección (estado actual roto). Descartada porque ya produjimos el bug una vez y la siguiente vez no tendrá defensa.

### 2. Defensa contra `&` residual en `$cmd`

**Decisión**: el trait detecta si `$cmd` termina en `&` (con o sin espacios antes) y lo recorta con `rtrim($cmd)` + `preg_replace('/\\s*&\\s*$/', '', $cmd)` antes de envolver. Loguea una sola línea `runs_background.stripped_trailing_ampersand logTag=X` si detecta el patrón (para que el operador pueda ver el refactor pendiente).

**Rationale**: mejor normalizar silenciosamente que romper. El log deja rastro para limpiar el caller eventualmente sin urgencia.

**Alternativa descartada**: lanzar excepción cuando el caller viola el contrato. Descartada porque hace que un caller "casi correcto" (con un espacio de más antes del `&`) se rompa en producción sin posibilidad de fallback.

### 3. Detección de fallo del wrapper: validación de sintaxis bash antes de ejecutar

**Decisión**: el trait valida la cadena final con `bash -n` mediante un subshell probe descartable. Si la validación falla, el trait NO hace `exec()` y escribe directamente en el cache compartido (solo si el caller pasó un `$cacheKey` opcional nuevo parámetro) o, si no hay cache key, loguea `runs_background.invalid_syntax` con la cadena completa. Como el problema conocido es específicamente del caller de `ApiTranscriptorController`, la opción realista es hacer la validación + retornar un valor que el caller pueda inspeccionar.

Versión final del contrato:

```php
protected function execBackground(
    string $cmd,
    string $logTag = 'unknown',
    ?string $logFile = null,
    ?string $cacheKey = null
): bool {
    // 1. Normalizar: recortar & residual
    $cmd = preg_replace('/\s*&\s*$/', '', $cmd);

    // 2. Validar sintaxis bash
    $probe = sprintf('bash -n %s 2>&1', escapeshellarg(
        sprintf('echo "%sstart $(date -Is)"; %s%s; echo "%send $(date -Is)"',
            $logTag . '] ', $cmd,
            $logFile ? ' >> ' . escapeshellarg($logFile) : '',
            $logTag . '] ')
        . ($logFile ? '' : ' >> /tmp/kilo_artisan_bg.log 2>&1')
    ));
    $validation = shell_exec('setsid ' . $probe . ' < /dev/null');
    if (trim($validation ?? '') !== '') {
        Log::error("runs_background.invalid_syntax tag={$logTag}: {$validation}");
        if ($cacheKey) {
            Cache::put($cacheKey, [
                'status' => 'error',
                'message' => "Lanzador produjo bash inválido: {$validation}. Revisá /tmp/kilo_artisan_bg.log y el filtro [{$logTag}].",
                ...
            ], now()->addHours(2));
        }
        return false;
    }

    // 3. Ejecutar el wrapper real
    $shellCmd = 'setsid bash -c ' . escapeshellarg(...) . ' &';
    exec($shellCmd);
    return true;
}
```

**Rationale**: convertir el trait de "fire and forget" a "fire and report" permite que los callers (especialmente `ApiTranscriptorController::processBatch`) traduzcan un fallo de wrapper en un cache `status: error` que el modal puede mostrar. La validación con `bash -n` es barata (no ejecuta nada, solo parsea) y captura exactamente la clase de bug que estamos arreglando.

**Alternativa descartada**: parsear bash manualmente con regex. Descartada porque `bash -n` es la fuente de verdad.

**Alternativa descartada**: capturar stderr del subshell real y esperar que bash escriba el error a algún lado accesible. Descartada porque con `setsid` + `&` el stderr se pierde.

### 4. Migrar los otros dos callers al nuevo contrato por consistencia

**Decisión**: en el mismo cambio, `CorreccionesController::apply` y `AvisosInteligentesController::scan` adoptan la nueva firma pasando `null` como `$logFile` (mantienen su comportamiento actual de solo-escribir-a-`/tmp/kilo_artisan_bg.log`). Su `liveness ping` actual (2 segundos, marcar error si el cache sigue en `queued`) sigue siendo defensa en profundidad.

**Rationale**: tres rutas idénticas = un solo lugar donde mirar cuando algo del lanzamiento se rompe. Si dejamos los otros dos con la firma vieja, el próximo refactor probablemente introduzca el bug de nuevo en alguno.

**Alternativa descartada**: dejar los otros dos callers sin tocar. Descartada porque la deuda técnica de "tres rutas con contratos distintos para la misma operación" es exactamente lo que produjo este bug.

## Risks / Trade-offs

- **[Cambio de firma del trait rompe callers externos]** → El trait es `protected`, así que solo los controllers del proyecto lo usan. Tres callers, todos migrados en el mismo cambio. Riesgo bajo.
- **`bash -n` puede dar falsos negativos en sintaxis válida]** → Probabilidad muy baja; `bash -n` es la misma gramática que `bash` real, solo sin ejecutar.
- **El log `runs_background.stripped_trailing_ampersand` puede ser ruidoso si hay callers con `&` residual en producción]** → Solo se emite UNA VEZ por proceso PHP-FPM worker (no se acumula) y el caller que lo produzca es trivial de detectar (es uno de los tres que migramos). Aceptable.
- **El polling frontend (2s) puede no alcanzar a ver `status: error` si el cache se escribe ANTES de que el frontend empiece a pollear** → No es problema: el frontend solo arranca el polling DESPUÉS de recibir el `200 OK` con el `run_id`, que ocurre después de la escritura. La ventana es ~3-5s entre el primer poll y el momento del `error`, y el cache tiene TTL de 2h. No hay race condition.
- **El nuevo parámetro `$cacheKey` acopla el trait al sistema de cache de Laravel** → Aceptable: el trait ya usa `Log` y `Cache` indirectamente vía Laravel facade. El acoplamiento es por conveniencia, no por abstracción rota.
- **Workers supervisord (queue:work transcription) NO necesitan reinicio** → El cambio vive en `Http/Controllers/Concerns/` y `Http/Controllers/Ia/`, que no son cargados por el worker. Solo PHP-FPM necesita un reload (graceful reload vía `USR2` o `reload` en pool).

## Migration Plan

1. Merge del feature branch.
2. En el deploy script (`deploy.sh`), agregar `nginx -s reload && kill -USR2 $(cat /run/php-fpm.pid)` (o equivalente según el sistema de PHP-FPM de TCloud) después del checkout para que el trait actualizado se cargue en workers PHP-FPM sin tumbar el front.
3. NO requiere reinicio de workers supervisord (`tcloud-transcription-batch-*`). NO requiere migración de BD.
4. Verificación post-deploy:
   - Click "Escanear storages" → la barra avanza en ≤ 5s y termina con resultados reales (no `starting` colgado).
   - `ls -la storage/logs/transcription-batch-{runId}.log` aparece el log nuevo.
   - `tail /tmp/kilo_artisan_bg.log` muestra `[transcriptor:scan] start ...` y `[transcriptor:scan] end ...` para el run.
5. Rollback: `git revert` del merge. El trait nuevo es compatible con callers que pasan comandos puros sin redirección, así que el revert no rompe a los otros dos controllers (ellos ya pasan comandos puros). La pérdida es solo el bug original.

## Open Questions

Ninguna. La elección entre `bash -n` y captura de stderr post-mortem se resolvió en `Decisions` §3.
