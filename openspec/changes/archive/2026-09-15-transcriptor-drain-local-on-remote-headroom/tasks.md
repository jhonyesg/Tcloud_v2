## 1. Quitar el freno por cola PG de los modos no-local

- [ ] 1.1 En `app/app/Console/Commands/TranscriptionTickCommand.php`, en el case `local_only` (líneas 379-386) MANTENER la rama `elseif ($checkPg() <= 0) { $skipped = true; $reason = 'queue_at_target'; }` tal cual.
- [ ] 1.2 En el mismo archivo, en el case `remote_aware` (líneas 388-401), REMOVER la línea `elseif ($checkPg() <= 0) { $skipped = true; $reason = 'queue_at_target'; }` (líneas 399-400). Verificar que el cierre `break;` queda bien.
- [ ] 1.3 En el mismo archivo, en el case `hybrid` (líneas 403-411), REMOVER la línea `elseif ($checkPg() <= 0) { $skipped = true; $reason = 'queue_at_target'; }` (líneas 407-408).
- [ ] 1.4 En el mismo archivo, en el case default (líneas 412-420), REMOVER la línea `elseif ($checkPg() <= 0) { $skipped = true; $reason = 'queue_at_target'; }` (líneas 417-418). (El default es por seguridad; el código real cae en uno de los 3 cases, pero si por algo llega al default también debería respetar el contrato.)
- [ ] 1.5 Verificar que la función helper `checkPg()` (líneas ~440-480) sigue retornando el conteo y que `values.pg_queue_depth` se sigue reportando (no se debe borrar el helper, solo quitar las ramas brake).
- [ ] 1.6 Verificar que el log resultante en `remote_aware` con cola local alta y remota vacía muestra `decision=dispatched, reason=none, values.pg_queue_depth=2028`. Comando de prueba: `cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app && php artisan transcription:tick --no-dispatch 2>&1 | head -20`.

## 2. Exponer `regulator_mode` y `target_remote_queue` en `cfgRuntime`

- [ ] 2.1 En `app/app/Http/Controllers/Ia/TranscriptorSettingsController.php`, dentro de `runtime()` (líneas 164-244), añadir al array retornado: `'regulator_mode' => $settings->str('regulator_mode')` y `'target_remote_queue' => $settings->int('target_remote_queue')`.
- [ ] 2.2 Verificar que el endpoint `GET /ia/api-transcriptor/runtime` (o el que se llame el que alimenta la tarjeta) devuelve ambos campos en JSON. Comando: `curl -s http://localhost/ia/api-transcriptor/runtime | jq` (ajustar host/auth según entorno).

## 3. UI condicional al modo en `_settings-tab.blade.php`

- [ ] 3.1 En `app/resources/views/ia/api-transcriptor/_settings-tab.blade.php`, localizar el bloque que muestra "El regulador frenaría: la cola está en/sobre el objetivo" (líneas ~76-80). Envolverlo en `@if(($cfgRuntime['regulator_mode'] ?? 'local_only') === 'local_only' && $cfgRuntime['next_batch'] === 0)`.
- [ ] 3.2 En el `@else` correspondiente, agregar un nuevo bloque que muestre: "Cola local: {N} (informativa en modo {regulator_mode} — la regulación la hace la API remota en {target_remote_queue})", usando los datos de `$cfgRuntime`.
- [ ] 3.3 Verificar visualmente: navegar a `/ia/api-transcriptor` con `regulator_mode=remote_aware`, confirmar que aparece el texto nuevo y NO el viejo "frenaría".
- [ ] 3.4 Verificar visualmente: cambiar `regulator_mode=local_only` desde la UI de Configuración, refrescar, confirmar que vuelve a aparecer el texto viejo cuando la cola local supera el target.

## 4. Cambiar el default de `regulator_mode` a `remote_aware`

- [ ] 4.1 En `app/app/Services/Ia/TranscriptorSettings.php`, línea 241, cambiar `'default' => 'local_only'` por `'default' => 'remote_aware'`.
- [ ] 4.2 Verificar que la constante `default` aplica solo a instalaciones nuevas: leer el método que escribe el schema a `system_settings` y confirmar que NO sobreescribe filas existentes.
- [ ] 4.3 NO migrar automáticamente la fila existente de `transcriptor.regulator_mode` en `system_settings`. Dejar que el operador decida.
- [ ] 4.4 Documentar el cambio en el `help` del setting (línea 245) si hace falta aclarar que el default cambió. Ej: agregar nota "(default cambió a `remote_aware` en este change)".

## 5. Validación end-to-end

- [ ] 5.1 Ejecutar el comando de validación en `local_only` y verificar que sigue frenando por `queue_at_target` cuando la cola local supera 140. Log esperado: `decision=skipped reason=queue_at_target`.
- [ ] 5.2 Cambiar a `remote_aware` desde la UI o con `php artisan tinker` (`SystemSetting::set('transcriptor.regulator_mode', 'remote_aware')`).
- [ ] 5.3 Ejecutar el tick con cola local alta (≥1000) y verificar `decision=dispatched` si la API remota está ociosa. Si la API remota está saturada, debe ser `decision=skipped reason=remote_queue_full`.
- [ ] 5.4 Refrescar la UI y verificar que el texto refleja el modo activo.
- [ ] 5.5 Inspeccionar `storage/logs/laravel.log` y confirmar que las decisiones del regulador son consistentes con el spec.

## 6. Rollback readiness

- [ ] 6.1 Capturar el commit hash del merge (lo provee el flujo de OpenSpec /opsx:apply al cerrar).
- [ ] 6.2 Documentar el comando de rollback en `design.md` Migration Plan (ya está).
- [ ] 6.3 No requiere migración de BD que revertir.
- [ ] 6.4 No requiere reinicio de workers ni supervisord.
