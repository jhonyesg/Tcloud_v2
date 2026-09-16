## MODIFIED Requirements

### Requirement: Modos del regulador configurables en caliente

El sistema SHALL soportar tres modos seleccionables desde la UI de settings y desde la variable de entorno `TRANSCRIPTOR_REGULATOR_MODE`:

- `local_only`: frena cuando `COUNT(*) FROM transcriptions WHERE state IN ('pending','queued') AND recorded_at >= today >= target_pg_queue` (default 140). Adicionalmente activa la guarda de `/dev/shm` libre.
- `remote_aware`: NO frena por cola PG local. Activa únicamente las señales remotas (`remote_ram_pressure`, `remote_ramdisk_pressure`, `remote_queue_full`), la guarda de `/dev/shm` y el inflight. La cola local queda solo como señal informativa en `values.pg_queue_depth` para que la UI la muestre sin usarla como freno.
- `hybrid`: dispara freno si CUALQUIERA de las señales configuradas reporta saturación: `remote_ram_pressure`, `remote_ramdisk_pressure`, `remote_queue_full`, `shm_low`, `inflight_full`, o `dispatch_paused=true`. Al igual que `remote_aware`, la cola PG local NO actúa como freno; queda como señal informativa.

El modo seleccionado se persiste en `system_settings` y el siguiente tick observa el cambio sin reinicio. La decisión cruda queda registrada en `storage/logs/laravel.log` con el prefijo del tick (`decision=`, `reason=`). La UI SHALL mostrar, dentro de la pestaña Configuración, el mensaje "El regulador frenaría: la cola está en/sobre el objetivo" SOLO cuando `regulator_mode == 'local_only'` Y `pg_queue_depth >= target_pg_queue`; en otros modos SHALL mostrar "Cola local: N (informativa en este modo — la regulación la hace la API remota en target_remote_queue)".

#### Scenario: Modo `local_only` con cola llena y GPU remota al 30%

- **WHEN** `regulator_mode=local_only`, `COUNT(*) FROM transcriptions WHERE state IN ('pending','queued') AND recorded_at >= today = 140` (>= target) y `processing_jobs` remoto < 50% de `remote_capacity`
- **THEN** el tick frena por `queue_at_target` aunque la GPU esté ociosa
- **AND** el log del tick muestra `decision=skipped reason=queue_at_target values.remote_gpu_usage=null`
- **AND** la UI muestra "El regulador frenaría: la cola está en/sobre el objetivo"

#### Scenario: Modo `remote_aware` con cola al 90% pero GPU al 25%

- **WHEN** `regulator_mode=remote_aware`, cola local al 90% del target y la API remota reporta procesamiento al 25%
- **THEN** el tick encola con el batch calculado (no frena por cola)
- **AND** `cfgRuntime.next_batch` muestra el batch positivo en la pestaña Configuración

#### Scenario: Modo `remote_aware` con cola local 14x target pero API remota ociosa

- **WHEN** `regulator_mode=remote_aware`, `pg_queue_depth=2028` (mucho mayor que `target_pg_queue=140`), y `queue_queued=10 <= floor_remote_queue=30` y `ram_pct=46 < remote_ram_pressure_pct=90` y `ramdisk_pct=12 < remote_ramdisk_pressure_pct=85` y `/dev/shm` libre
- **THEN** el tick NO frena por cola local: `decision=dispatched`, `reason=none`
- **AND** `values.pg_queue_depth=2028` se reporta como informativo pero no afecta `batch_computed`
- **AND** la UI muestra "Cola local: 2028 (informativa en este modo — la regulación la hace la API remota en 180)" y NO muestra el texto "frenaría"

#### Scenario: Modo `remote_aware` con cola local 14x target y API remota saturada

- **WHEN** `regulator_mode=remote_aware`, `pg_queue_depth=2028` y `queue_queued=200 >= target_remote_queue=180`
- **THEN** el tick frena por `decision=skipped, reason=remote_queue_full`, NO por `queue_at_target`
- **AND** la UI sigue mostrando "Cola local: 2028 (informativa en este modo)" y añade la causa "Cola remota llena (200 >= 180)"

#### Scenario: Modo `hybrid` con cola local 14x target pero todas las señales remotas y locales sanas

- **WHEN** `regulator_mode=hybrid`, `pg_queue_depth=2028`, `queue_queued=10`, `ram_pct=46`, `ramdisk_pct=12`, `/dev/shm` libre, `inflight_active < inflight_max`, `dispatch_paused=false`, circuit cerrado
- **THEN** el tick NO frena por cola local: `decision=dispatched, reason=none`
- **AND** el batch se calcula por `computeEffectiveBatch` con el `pulse_batch_size` (default 50) sujeto a `max_batch` y `stuck_penalty`
- **AND** la UI muestra "Cola local: 2028 (informativa en este modo)"

#### Scenario: Modo `hybrid` con cualquier señal saturada

- **WHEN** `regulator_mode=hybrid` y DOS o más señales indican saturación simultáneas (cola remota llena + RAM remota >80%)
- **THEN** el tick registra la señal que PRIMERO disparó (`signals_evaluated` en orden) y frena
- **AND** el log indica cuál fue la causa dominante y cuáles eran secundarias
- **AND** la causa NO es `queue_at_target` aunque la cola local esté sobre el target (porque la cola PG ya no es freno en `hybrid`)
