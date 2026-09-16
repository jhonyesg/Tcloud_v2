# transcriptor-pg-worker-process Specification

## Purpose

Definir el contrato del comando `transcription:worker` que reemplaza la unidad
supervisord `tcloud-transcription-batch-*` (la cual ejecutaba `queue:work
--queue=transcription` con jobs Redis). El worker es ahora un proceso PHP-FPM que
polea directamente `transcriptions` con `FOR UPDATE SKIP LOCKED`, ejecutando la
lógica ffmpeg + POST al upstream de forma serial por unidad.

## ADDED Requirements

### Requirement: Comando `transcription:worker` con flags `--storage`, `--batch`, `--idle-sleep`

El sistema SHALL exponer `php artisan transcription:worker` con signature:

```
transcription:worker
  {--storage=ALL : Filtrar por storage_provider_id (ALL = todos)}
  {--batch=1 : Cantidad de filas por iteración del loop}
  {--idle-sleep=2 : Segundos de pausa cuando no hay candidatos}
```

#### Scenario: Worker default arranca y procesa sin intervención

- **WHEN** el operador lanza `php artisan transcription:worker`
- **THEN** el loop arranca, busca candidatos, procesa el primero, duerme 2s si no
  hay más, repite indefinidamente

#### Scenario: Worker filtrado por storage

- **WHEN** el operador lanza `php artisan transcription:worker --storage=5`
- **THEN** el query filtra adicionalmente `files.storage_provider_id = 5`

### Requirement: Signal handling SIGTERM / SIGINT graceful

El sistema SHALL registrar handlers de `pcntl_signal(SIGTERM, $handler)` y
`pcntl_signal(SIGINT, $handler)` que ponen una bandera `$shouldStop = true`. El
loop SHALL chequear la bandera **después** de cada iteración completa (procesar +
UPDATE estado) para garantizar que no se queda a mitad de un job.

#### Scenario: supervisord envía SIGTERM al reiniciar

- **WHEN** supervisord decide reiniciar el worker (e.g. crash previo, deploy)
- **THEN** el worker termina el job en curso, persiste el estado final, y sale con
  código 0; supervisord arranca la nueva unit sin jobs a medias

#### Scenario: Operador hace Ctrl+C en consola

- **WHEN** el operador hace Ctrl+C mientras el worker corre
- **THEN** el worker termina la iteración actual y sale limpiamente

### Requirement: Manejo de `Throwable` no mata el worker

El bloque `try/catch` SHALL envolver la llamada a `TranscriptionSubmitService::submit()`.
Cualquier `Throwable` SHALL marcar la fila `state='error'` con `error_message=$e->getMessage()`
(vía `markError` ya existente en el service) y continuar con la siguiente.

#### Scenario: Excepción inesperada de FFmpeg

- **WHEN** ffmpeg crashea con `Segmentation fault` durante la conversión
- **THEN** el worker captura, marca `state='error'`, loguea con `Log::error`,
  y continúa con la siguiente fila sin abortar el proceso

#### Scenario: OutOfMemory en ffmpeg

- **WHEN** el sistema mata ffmpeg por OOM
- **THEN** el worker detecta el exit code ≠ 0, marca `state='error'`, continúa

### Requirement: Log estructurado por job procesado

El worker SHALL emitir `Log::info('transcriptor.worker.processed', [...])` con:

- `file_id` (int)
- `storage_provider_id` (int)
- `duration_ms` (int): tiempo total del job
- `result` (`ok | requeue | error | dead`)
- `job_id` (string|null): si se obtuvo del upstream
- `error_message` (string|null): si falló

#### Scenario: Job exitoso en 12.4s

- **WHEN** el worker termina un submit con POST exitoso
- **THEN** `Log::info('transcriptor.worker.processed', ['file_id' => 4711,
  'storage_provider_id' => 5, 'duration_ms' => 12400, 'result' => 'ok',
  'job_id' => 'abc-123', 'error_message' => null])` aparece en `laravel.log`

### Requirement: Paralelismo vía N units supervisord

El sistema SHALL desplegar `numprocs=3` units supervisord (configurable vía
supervisord.conf) llamadas `tcloud-transcription-worker_00`, `_01`, `_02`. Cada
unit corre una instancia del comando. La concurrencia intra-proceso es 1 (un ffmpeg
a la vez), la concurrencia total es N=3 ffmpeg simultáneos.

#### Scenario: 3 workers procesan en paralelo sin pisarse

- **WHEN** hay 50 audios pendientes y 3 workers corriendo
- **THEN** cada worker toma 1 fila distinta por iteración (FOR UPDATE SKIP LOCKED
  garantiza la exclusión); el sistema procesa ~3 filas en paralelo

#### Scenario: Operador sube numprocs a 5

- **WHEN** el operador edita supervisord.conf con `numprocs=5` y hace
  `supervisorctl update`
- **THEN** supervisord arranca 2 unidades adicionales; total = 5 workers paralelos

### Requirement: Unit template `tcloud-transcription-worker.conf` versionado en repo

El sistema SHALL mantener el template de la unit en
`app/config/supervisor/tcloud-transcription-worker.conf` con:

- `command=php /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app/artisan transcription:worker --storage=ALL`
- `numprocs=3`
- `stopwaitsecs=3600` (espera hasta 1h para que termine el job en curso antes de SIGKILL)
- `stdout_logfile=/var/log/tcloud/transcription-worker.log`

#### Scenario: Deploy incluye el template

- **WHEN** se ejecuta `deploy.sh` o el procedimiento equivalente
- **THEN** el template se copia a `/etc/supervisor/conf.d/tcloud-transcription-worker.conf`
  y `supervisorctl reread && supervisorctl update` lo activa

### Requirement: Compatibilidad con `dispatch_paused` y `TRANSCRIPTOR_DISPATCH_PAUSED`

El worker SHALL chequear al inicio de cada iteración:

- `SystemSetting('dispatch_paused') == '1'`, OR
- `env('TRANSCRIPTOR_DISPATCH_PAUSED') == 'true'`

Si alguna está activa, SHALL hacer `sleep($idle_sleep_seconds)` sin tomar
candidatos. Mismo freno de emergencia que el tick tenía.

#### Scenario: dispatch_paused=true activa freno de emergencia

- **WHEN** el admin activa el freno vía UI
- **THEN** el siguiente tick del cron NO encola; los workers tampoco toman candidatos;
  las filas `pending` quedan intactas en BD

#### Scenario: dispatch_paused=false reanuda

- **WHEN** el admin desactiva el freno
- **THEN** en el siguiente ciclo del worker (≤ 2 s) toma candidatos nuevamente