## Context

- El regulador del tick vive en `TranscriptionTickCommand::evaluateRegulator()` (4 modos en un `match`, `app/app/Console/Commands/TranscriptionTickCommand.php:328-420`). Hoy, los 4 modos terminan evaluando `checkPg() <= 0` y disparan `reason=queue_at_target`. El bug: en `remote_aware` e `hybrid` la cola PG local no debería frenar (ver spec `transcriptor-regulator-signals` escenario 23-26 que el código nunca implementó correctamente).
- La UI lee `cfgRuntime.next_batch === 0` para mostrar el texto "El regulador frenaría…" (`app/resources/views/ia/api-transcriptor/_settings-tab.blade.php:76-80`). Hoy ese texto sale en los 4 modos cuando la cola local supera el target, incluyendo `remote_aware` donde la cola local ya no debería frenar.
- El setting `transcriptor.regulator_mode` está en `TranscriptorSettings::SCHEMA` (`app/app/Services/Ia/TranscriptorSettings.php:240-254`) con default `local_only`. La fila se persiste en `system_settings` la primera vez que se lee el schema, así que cambiar el default solo afecta instalaciones nuevas; la fila existente queda con el valor previo hasta que se edite manualmente.
- El worker PG (`transcription:worker`) y el burst-dispatcher (`transcription:burst-dispatch`) NO se tocan: siguen su flujo actual. La corrección es 100% regulatoria.

## Goals / Non-Goals

**Goals:**
- Que en `remote_aware` e `hybrid` la cola local NO sea freno; las únicas razones de skip son: circuit breaker, RAM remota, ramdisk remota, cola remota llena, /dev/shm bajo, inflight full, dispatch_paused.
- Que la UI distinga visualmente entre "freno real" y "cola informativa" según el modo.
- Que instalaciones nuevas arranquen en `remote_aware` por default.
- Cero cambios en workers, DB, supervisord, dependencias externas.

**Non-Goals:**
- No se cambia la prioridad ni el orden de evaluación de las señales restantes.
- No se reescribe el worker PG para batching; sigue tomando 1 fila por iteración con `numprocs=12`.
- No se introduce ninguna señal nueva.
- No se modifica `dispatch_paused`, el circuit breaker, ni el burst-dispatcher.
- No se migra automáticamente la fila existente en `system_settings` (`local_only`); el operador decide.

## Decisions

### Decisión 1: Eliminar el case `queue_at_target` de los modos `remote_aware` y `hybrid`

**Por qué**: el spec ya dice que estos modos no deben frenar por cola local (escenarios 23-26 y el nuevo que añade el delta). Mantener el case contradice el contrato.

**Alternativa considerada**: dejar el case pero añadir un override `target_pg_queue` muy alto (ej. 10000) en runtime. Descartada porque: (a) sigue evaluando en cada tick desperdiciando un query COUNT, (b) la señal quedaría visible solo por accidente, no por diseño, y (c) si el operador baja el setting volvería a frenar.

**Cómo**: mover las ramas `elseif ($checkPg() <= 0) { $skipped = true; $reason = 'queue_at_target'; }` SOLO al case `local_only` (líneas 384-385 y 417-418). Remover de los cases `remote_aware` (399-400) e `hybrid` (407-408). Mantener `checkPg()` como función para conservar `values.pg_queue_depth` en el output.

### Decisión 2: Cambiar el default de `regulator_mode` a `remote_aware`

**Por qué**: el bug original (frenar por cola local en modos no-local) estaba escondido detrás del default `local_only`. Cualquier operador que dejara el default nunca tocaba el bug. Cambiar el default a `remote_aware` expone el bug si reaparece (los scenarios del spec fallarían) y da el comportamiento esperado al usuario nuevo.

**Alternativa considerada**: dejar `local_only` como default y agregar un notice en la UI sugiriendo cambiar a `remote_aware`. Descartada porque la fricción operativa es alta y el spec ya declara `remote_aware` como el modo correcto.

**Caveat**: la fila en `system_settings` ya existente NO se migra automáticamente. Si el operador la dejó en `local_only` deliberadamente (porque NO quiere validar contra la API remota), el cambio de default no le afecta. Si quiere migrar, edita el setting desde la UI.

### Decisión 3: Hacer el mensaje de UI condicional al modo

**Por qué**: hoy el texto "El regulador frenaría: la cola está en/sobre el objetivo" se renderiza siempre que `next_batch === 0` (`_settings-tab.blade.php:76-80`). Eso es engañoso en `remote_aware`/`hybrid` donde la cola local no es freno y `next_batch === 0` solo cuando TODAS las señales remotas disparan.

**Alternativa considerada**: cambiar `next_batch === 0` por `decision === 'skipped'` para mostrar el texto solo cuando realmente se saltó. Descartada porque `decision` es del último tick (cacheado), mientras que `next_batch` se recalcula; mantener el chequeo por `next_batch === 0` es consistente con el resto de la UI y agrega solo la rama del modo.

**Cómo**: en `_settings-tab.blade.php` líneas 76-80, envolver el bloque en `@if($cfgRuntime['regulator_mode'] === 'local_only' && $cfgRuntime['next_batch'] === 0)`. En el `@else` mostrar el texto informativo nuevo: "Cola local: {N} (informativa en modo {mode} — la regulación la hace la API remota en {target_remote_queue})".

### Decisión 4: Pasar `regulator_mode` y `target_remote_queue` al payload de `cfgRuntime`

**Por qué**: la UI necesita leer ambos para renderizar el texto nuevo. Hoy `cfgRuntime` viene de `TranscriptorSettingsController::runtime()` (`app/app/Http/Controllers/Ia/TranscriptorSettingsController.php:164-244`).

**Alternativa considerada**: leer `regulator_mode` directamente desde `SystemSetting` en la vista. Descartada porque rompe el patrón del endpoint (la vista no debería pegar a la capa de settings).

**Cómo**: añadir `regulator_mode => $settings->str('regulator_mode')` y `target_remote_queue => $settings->int('target_remote_queue')` al array retornado por `runtime()`. Esos dos campos ya existen en `TranscriptorSettings::SCHEMA`.

## Risks / Trade-offs

- [Riesgo] Operadores con `regulator_mode=local_only` no notan ningún cambio (la rama local_only sigue frenando por cola). Mitigación: el changelog del change y la UI deben comunicar que el cambio aplica a `remote_aware`/`hybrid`; el modo `local_only` sigue siendo el freno explícito por cola local para quien lo prefiera.
- [Riesgo] Si la API remota está caída y `getRemoteInfo()` retorna `null`, en `remote_aware` el regulador falla open (no frena por señales remotas, según spec escenario 99-101). Combinado con "cola local ya no es freno" significa que con API caída el regulador NO frenaría NADA. Mitigación: en `hybrid` la guarda sigue activa (local tampoco frena pero las otras sí). En `remote_aware` el operador debe entender que está confiando en la API; la doc del setting (`detail.cuando_tocar` línea 251) ya lo aclara. Como red de seguridad adicional, las guardas de `/dev/shm`, inflight y circuit breaker siguen activas en `remote_aware`.
- [Riesgo] Cambiar el default puede hacer que instalaciones nuevas drenen agresivamente la cola sin que el operador lo note. Mitigación: el `pulse_batch_size=50` + `max_batch=200` + el ramp por cola remota ya acotan el envío; la rampa encola menos cuando `queue_queued` se acerca a `target_remote_queue=180`.
- [Riesgo] La señal `pg_queue_depth` se sigue reportando en `values` y en la UI (informativa). Mitigación: la UI la etiqueta explícitamente como "informativa" para evitar confusiones.

## Migration Plan

**Deploy**:
1. `git pull` con el merge del change.
2. `php artisan config:cache` (no aplica a este change, no se tocó `.env` ni config, pero por hábito).
3. `php artisan view:cache` para refrescar la blade `_settings-tab.blade.php`.
4. Sin reinicio de workers (no se tocó `TranscriptionWorkerCommand` ni `TranscriptionTickCommand` no-cacheable en boot).
5. Sin migración de BD.

**Validación post-deploy**:
1. Verificar que `transcription:tick` en modo `remote_aware` con cola local grande y remota vacía encola (no frena). Log esperado: `decision=dispatched, reason=none, values.pg_queue_depth=2028`.
2. Verificar que `transcription:tick` en modo `remote_aware` con cola local grande y remota llena sigue frenando. Log esperado: `decision=skipped, reason=remote_queue_full`.
3. Verificar que la UI en `remote_aware` muestra el texto informativo y NO muestra "El regulador frenaría".
4. Verificar que `local_only` se comporta idéntico al estado previo (regresión).

**Rollback**:
- `git revert <commit-hash>` (único archivo crítico: `TranscriptionTickCommand.php` + `_settings-tab.blade.php` + `TranscriptorSettings.php`).
- `php artisan view:cache` para refrescar la blade revertida.
- Sin reinicio de workers. Sin migración que revertir. La fila existente de `transcriptor.regulator_mode` en `system_settings` no se tocó en este change, así que el revert devuelve el sistema al estado pre-change sin perder la elección del operador.

**Freno de emergencia alternativo (sin deploy)**: si el operador quiere conservar el comportamiento viejo sin revertir, edita `transcriptor.regulator_mode=local_only` desde la UI de Configuración. Vuelve al freno por cola local.

## Open Questions

Ninguna. Las decisiones tomadas son suficientes para implementar y validar contra los scenarios del delta spec.
