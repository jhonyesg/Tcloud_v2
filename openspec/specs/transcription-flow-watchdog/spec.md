# transcription-flow-watchdog Specification

## Purpose
Detectar que el pipeline de transcripción dejó de producir, con independencia de qué pieza falle. Nace del incidente del 2026-08-18: el pipeline estuvo 44 horas parado y ninguna pieza avisó, porque cada una informaba correctamente su propio estado ("no hay pending", "workers objetivo 0") y ninguna podía saber que el sistema global estaba muerto.

## Requirements

### Requirement: Centinela horario de producción de transcripciones

El sistema SHALL ejecutar `transcription:health-check` cada hora vía Laravel Scheduler, con `withoutOverlapping(30)`, y SHALL avisar cuando no se haya creado ninguna fila en `transcriptions` dentro de las últimas N horas (parámetro `--hours`, default `3`).

El aviso SHALL registrarse siempre como `Log::warning` en `laravel.log` incluyendo la última transcripción conocida, el umbral, el número de storages habilitados y un diagnóstico accionable. El correo SHALL ser opcional y best-effort: sin destinatario (`--to` o `transcriptor.health_alert_email`), sin plantilla `alerta-sistema` o sin SMTP, el comando SHALL terminar sin lanzar excepción.

La sonda de "última transcripción" SHALL resolverse por clave primaria (`ORDER BY id DESC LIMIT 1`) y NO por un filtro sobre `created_at`: no existe índice por esa columna sola y la tabla ronda las 240k filas.

#### Scenario: El pipeline lleva horas sin producir
- **WHEN** la última fila de `transcriptions` es anterior a `now() - hours`
- **THEN** el comando escribe `WARNING` en `laravel.log`, imprime el diagnóstico y retorna código de fallo

#### Scenario: El pipeline está vivo
- **WHEN** existe al menos una transcripción creada dentro de la ventana
- **THEN** el comando informa "Pipeline vivo" con la fecha de la última y el número de storages habilitados, y retorna éxito

#### Scenario: No queda ningún storage habilitado
- **WHEN** se dispara el aviso y `storage_providers.transcription_enabled` no es `true` en ninguna fila
- **THEN** el diagnóstico SHALL nombrar esa causa explícitamente y remitir a `/ia/api-transcriptor` para encender los canales

#### Scenario: Caída larga con destinatario configurado
- **WHEN** el aviso se dispara varias veces seguidas y hay destinatario configurado
- **THEN** el correo se envía una sola vez por ventana de 6 horas (cooldown en cache), mientras el `WARNING` en log se registra en cada corrida

### Requirement: El pipeline sin storages habilitados es ruidoso, no silencioso

El sistema SHALL distinguir en los logs el estado "ocioso y sano" del estado "sin ningún storage habilitado", que antes producían la misma línea.

`TranscriptionTickCommand` SHALL registrar `WARNING` (no `info`) cuando no haya pendientes que despachar **y** el número de storages con `transcription_enabled=true` sea 0, incluyendo ese conteo en la línea de salida.

`TranscriptionTuneCommand` SHALL registrar `WARNING` en `laravel.log` antes de apagar el pool completo por ausencia de storages habilitados, indicando cuántos workers estaban activos.

#### Scenario: Tick sin pendientes con storages habilitados
- **WHEN** no hay `Transcription` pendientes del día y hay al menos un storage habilitado
- **THEN** el tick registra `info` "no pending today" con el conteo de storages habilitados

#### Scenario: Tick sin ningún storage habilitado
- **WHEN** no hay pendientes y ningún storage tiene `transcription_enabled=true`
- **THEN** el tick registra `WARNING` señalando que no hay nada que descubrir ni enviar, con la pista de encender canales en `/ia/api-transcriptor`

#### Scenario: Tune apaga el pool entero
- **WHEN** `transcription:tune --apply` encuentra 0 storages habilitados y detiene las units systemd
- **THEN** registra `WARNING` en `laravel.log` con el número de workers que estaba apagando, antes de detenerlos
