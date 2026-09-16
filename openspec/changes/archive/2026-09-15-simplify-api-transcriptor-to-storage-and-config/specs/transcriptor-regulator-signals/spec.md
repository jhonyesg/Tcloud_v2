## MODIFIED Requirements

### Requirement: Modos del regulador configurables en caliente

El sistema SHALL soportar tres modos seleccionables desde la UI de settings y desde la variable de entorno `TRANSCRIPTOR_REGULATOR_MODE`:

- `local_only` (default inicial): conserva el comportamiento actual basado en `target_redis_queue`; solo activa además la guarda de `/dev/shm` libre.
- `remote_aware`: además de `local_only`, consulta `GET /api/stats` en la API externa con caché de N segundos (`TRANSCRIPTOR_REGULATOR_REMOTE_CACHE_SECONDS`, default 15) y frena si `processing_jobs / remote_capacity >= TRANSCRIPTOR_REGULATOR_REMOTE_SATURATION_PCT` (default 80).
- `hybrid`: dispara freno si CUALQUIERA de las señales configuradas reporta saturación: `redis_queue_depth >= target_redis_queue`, `remote_gpu_usage >= umbral`, `shm_free_bytes < min_shm_free_bytes`, `inflight_active >= inflight_max`, o `dispatch_paused=true`.

El modo seleccionado se persiste en `system_settings` y el siguiente tick observa el cambio sin reinicio. La decisión cruda queda registrada en `storage/logs/laravel.log` con el prefijo del tick (`decision=`, `reason=`). La UI NO SHALL mostrar un panel lateral con la explicación humana de la decisión: esa información se resume en `cfgRuntime.next_batch` dentro de la misma pestaña Configuración.

#### Scenario: Modo `local_only` con cola llena y GPU remota al 30%

- **WHEN** `regulator_mode=local_only`, `Redis::llen('queues:transcription')=140` (>= target) y `processing_jobs` remoto < 50% de `remote_capacity`
- **THEN** el tick frena por `queue_at_target` aunque la GPU esté ociosa
- **AND** el log del tick muestra `decision=skipped reason=queue_at_target values.remote_gpu_usage=null`

#### Scenario: Modo `remote_aware` con cola al 90% pero GPU al 25%

- **WHEN** `regulator_mode=remote_aware`, cola local al 90% del target y la API remota reporta procesamiento al 25%
- **THEN** el tick encola con el batch calculado (no frena por cola)
- **AND** `cfgRuntime.next_batch` muestra el batch positivo en la pestaña Configuración

#### Scenario: Modo `hybrid` con cualquier señal saturada

- **WHEN** `regulator_mode=hybrid` y DOS o más señales indican saturación simultáneas (cola llena + GPU >80%)
- **THEN** el tick registra la señal que PRIMERO disparó (`signals_evaluated` en orden) y frena
- **AND** el log indica cuál fue la causa dominante y cuáles eran secundarias