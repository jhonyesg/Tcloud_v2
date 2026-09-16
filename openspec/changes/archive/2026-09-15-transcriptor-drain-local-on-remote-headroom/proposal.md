## Why

El regulador del tick automático del módulo API Transcriptor tiene un bug: en los modos `remote_aware` y `hybrid` sigue frenando por `queue_at_target` cuando la cola PG local supera `target_pg_queue=140`, contradiciendo el spec `transcriptor-regulator-signals` escenario 23-26 que dice explícitamente "el tick encola con el batch calculado (no frena por cola)" en `remote_aware`. El operador ve en el panel "Cola de despacho 2028/140 — El regulador frenaría: la cola está en/sobre el objetivo" y deduce erróneamente que el sistema está saturado, cuando en realidad la API remota está ociosa y el regulador debería estar drenando la pila local.

Resultado: con la API remota reportando `queue_queued << 180`, el cron automático no envía nada, la cola local crece monótonamente con cada escaneo (2028 → 3000+), y el operador tiene que lanzar manualmente `transcription:burst-dispatch` para forzar el drenaje.

## What Changes

- **`TranscriptionTickCommand::evaluateRegulator()`** — quitar la guarda `checkPg() <= 0 → queue_at_target` de los modos `remote_aware` y `hybrid`. La cola PG local deja de ser freno cuando el modo no es `local_only`; queda solo como señal informativa en `values.pg_queue_depth` para que la UI la siga mostrando.
- **`TranscriptorSettings`** — cambiar el default de `regulator_mode` de `local_only` a `remote_aware` para que instalaciones nuevas arranquen con el comportamiento correcto (drenar en función de la API remota). La fila existente en `system_settings` NO se toca — el operador decide si migrar.
- **`_settings-tab.blade.php`** — el mensaje "El regulador frenaría: la cola está en/sobre el objetivo" pasa a ser condicional al modo `local_only`. En otros modos se muestra "Cola local: N (informativa, no es freno en este modo) — la regulación la hace la API remota en M".
- Sin cambios en workers PG, en `transcriptor-burst-dispatch`, en el cron, en migraciones ni en supervisor.

## Capabilities

### Modified Capabilities
- `transcriptor-regulator-signals`: el modo `remote_aware` ya no frena por `pg_queue_depth`; el modo `hybrid` reduce su señal `pg_queue_depth` a informativa. El comportamiento del modo `local_only` NO cambia. Escenario nuevo: "Modo `remote_aware` con cola local 10x target y cola remota vacía → envía hasta saturar la remota, no frena por cola local."

## Impact

- **Código**: `app/app/Console/Commands/TranscriptionTickCommand.php` (líneas ~385, ~399-400, ~407-408, ~417-419), `app/app/Services/Ia/TranscriptorSettings.php` (línea 241, default de `regulator_mode`), `app/resources/views/ia/api-transcriptor/_settings-tab.blade.php` (líneas ~65-92).
- **Config**: setting `transcriptor.regulator_mode` puede quedar en `local_only` por compatibilidad; valor nuevo default `remote_aware`.
- **APIs externas**: ninguna.
- **DB**: ninguna migración.
- **Workers / supervisord**: sin cambios.
- **Downstream**: el cambio cumple la constraint `api_transcriptor_refactor_preserve_downstream_to_corrections_and_avisos`: el regulador solo cambia QUÉ se encola, no el flujo downstream hacia Correcciones / Avisos.

## Non-goals

- No se modifica el worker PG ni el burst-dispatcher.
- No se cambia el tamaño de lote por defecto (sigue en `min_batch=10`, `max_batch=200`, `pulse_batch_size=50`).
- No se introducen nuevas señales de freno.
- No se cambia la prioridad de evaluación de frenos (sigue siendo: circuit → RAM remota → ramdisk remota → cola remota → shm → inflight).
- No se modifica el comportamiento de `dispatch_paused` ni el circuit breaker.
- No se migra automáticamente el setting existente en `system_settings` (queda a criterio del operador).
