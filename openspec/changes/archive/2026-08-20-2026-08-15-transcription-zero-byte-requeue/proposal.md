# Change: Reencolar transcripciones descartadas por tamaño 0 bytes (no marcarlas dead)

## Why

El grabador de radio/televisión crea el archivo de audio al iniciar la grabación (0 bytes) y lo va llenando mientras el stream llega. Si el stream de red se congela (ej. `playerservices.streamtheworld.com` deja de enviar datos), el archivo permanece en 0 bytes durante minutos.

El pipeline de transcripción (`TranscriptionSubmitService::submit`) descarta el archivo como `dead` cuando `filesize() < min_file_size_bytes` (1MB). El mensaje es "Archivo descartado por tamano (0 bytes < 1048576 minimo). Probable archivo truncado por grabador en vivo."

**Problema:** el descarte es **prematuro e irreversible**. Cuando el stream se descongela, el archivo crece (ej. Caracol pasó de 0 a 267KB en 7 min), pero la `Transcription` ya quedó en `dead` y **nunca se vuelve a intentar**. El audio existe en disco pero la transcripción se pierde para siempre.

**Impacto medido (2026-08-15):** 815 transcripciones marcadas `dead` por "0 bytes" en un solo día, ~35-43 por hora de forma consistente. Histórico: 1,681. El `backfill-lost` NO cubre este caso (solo rescata filas con `upstreamLost()` = SRT perdido / job inexistente).

## What Changes

### 1. `TranscriptionSubmitService::submit()` — descarte 0 bytes → reencolable

Cuando el archivo es menor a `min_file_size_bytes`:
- **NO** marcar `dead` inmediatamente.
- Marcar como **reencolable** (`markRequeueable()`) con `requeue_after_at` futuro, para que el tick lo reintente en el siguiente ciclo.
- El reintento naturalmente espera a que el archivo crezca (el grabador lo llena en ~21 min).

**Excepción:** si el archivo tiene 0 bytes **y** su `filemtime` es muy antiguo (p. ej. > `requeue_after_minutes`), el archivo probablemente está truncado de verdad (grabador falló) y no merece reintentos infinitos. Se mantiene `dead` en ese caso.

### 2. Límite de reintentos para no encolar basura para siempre

Para evitar que un archivo genuinamente corrupto rebote infinitamente, se respeta el contador `retries` existente: si `retries >= max_retries`, se marca `dead` (comportamiento actual). El descarte por tamaño pasa a ser un "intento" que incrementa `retries`, igual que un fallo de ffmpeg.

## Non-goals

- **No** cambiar `min_file_size_bytes` (1MB es correcto para filtrar radios truncadas).
- **No** agregar un comando de backfill nuevo: el reintento automático en el tick cubre el caso.
- **No** tocar el `backfill-lost` existente (sigue cubriendo SRT perdido / job inexistente).
- **No** modificar el grabador ffmpeg (el congelamiento de stream es externo).

## Impact

- **Code affected (modificado):**
  - `app/app/Services/Ia/TranscriptionSubmitService.php`
- **Migrations:** ninguna.
- **Riesgos:** bajo — solo cambia el estado de salida del filtro de tamaño (dead → pending+requeue_after_at). El tick ya maneja `requeue_after_at` (lo ignora hasta el plazo).
