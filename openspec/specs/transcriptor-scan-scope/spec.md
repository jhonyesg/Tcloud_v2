# transcriptor-scan-scope Specification

## Purpose

Define que el alcance de escaneo del API Transcriptor sea elegible desde la UI (hoy / rango de fechas / histórico completo), con estimación previa de trabajo y ejecución en background con progreso por storage — sin bypassar el regulador de envío.

## Requirements

### Requirement: El operador elige el alcance del escaneo desde la UI

El modal "Escanear storages" SHALL ofrecer tres alcances: (a) Hoy (comportamiento por defecto idéntico al actual), (b) Rango de fechas con desde/hasta, (c) Todo el histórico. El alcance elegido SHALL traducirse a opciones del comando de descubrimiento (`--days=0`, `--from/--to`, `--all`) sin alterar la fase de envío ni el regulador de cola.

#### Scenario: Escaneo de hoy no cambia
- **WHEN** el operador lanza el escaneo con el alcance por defecto
- **THEN** el comando corre con `--days=0` y solo procesa la carpeta `dmY` de hoy (identico al comportamiento vigente)

#### Scenario: Rango de fechas construye la lista de carpetas
- **WHEN** el operador elige el rango 2026-09-01 a 2026-09-05 y lanza el escaneo
- **THEN** el comando descubre archivos en las carpetas `01092026`..`05092026` de cada storage habilitado, sin tocar el resto

#### Scenario: Histórico completo descubre sin saturar el envío
- **WHEN** el operador elige "Todo el histórico"
- **THEN** el descubrimiento recorre recursivamente todas las carpetas `dmY` de los storages habilitados y crea transcripciones `pending`
- **AND** el envío a cola sigue limitado por el regulador (`scan_max_dispatch_per_cycle`), con el resto de `pending` esperando el cron

### Requirement: El modal estima el trabajo antes de lanzar

El sistema SHALL exponer un endpoint de estimación que, para el alcance elegido, retorne con queries acotadas: archivos sin transcripción del rango, transcripciones en `error` con archivo vivo, y transcripciones `dead` recuperables (upstream-lost). El modal SHALL mostrar estos conteos antes del botón de lanzamiento. La estimación SHALL NOT mutar nada.

#### Scenario: Estimación de un rango pequeño
- **WHEN** el operador pide estimación para el rango de los últimos 3 días
- **THEN** el modal muestra "N archivos sin transcribir, M error recuperables" y no crea ninguna fila

#### Scenario: Estimación del histórico completo
- **WHEN** el operador pide estimación con "Todo el histórico"
- **THEN** el modal muestra el total de archivos sin transcripción en storages habilitados, con advertencia del tiempo estimado de descubrimiento

### Requirement: El reenvío de fallidos respeta el alcance elegido

Cuando el operador activa "Reintentar fallidos" con un rangeo elegido, el sistema SHALL reintentar solo las transcripciones en `error` cuyo `created_at` (o el día de su archivo) esté dentro del rango. Las `dead` upstream-lost SHALL ofrecerse vía el comando de recuperación dedicado, y las `dead` por audio ausente SHALL permanecer terminales.

#### Scenario: Reintento acotado al rango
- **WHEN** el operador activa "Reintentar fallidos" con rango de 7 días
- **THEN** solo se reencolan transcripciones en `error` del rango con archivo accesible y retries < máximo

#### Scenario: Dead irrecuperable no se reintenta
- **WHEN** una transcripción `dead` tiene `error_message` de audio ausente/corrupto
- **THEN** el escaneo la ignora (permanece `dead`) y la estimación no la cuenta como recuperable

### Requirement: El run en background reporta progreso por storage

El escaneo con alcance ampliado SHALL registrar en la cache del runId el plan de storages y el avance por storage (escaneados / creados / errores), para que el modal y el widget global muestren el progreso. Si el proceso se interrumpe, la estimación puede re-lanzarse y los archivos ya creados no se duplican (idempotencia por file_id).

#### Scenario: Modal muestra avance del histórico
- **WHEN** el escaneo con "Todo el histórico" corre en background
- **THEN** el modal consulta `/batch-status/{runId}` y muestra por storage: escaneados, archivos creados, transcripciones creadas

#### Scenario: Re-ejecución no duplica
- **WHEN** el mismo rango se escanea dos veces
- **THEN** la segunda corrida reporta 0 archivos creados (todos ya tienen File y Transcription)