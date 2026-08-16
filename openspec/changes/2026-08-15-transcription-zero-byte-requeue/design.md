# Design: Reencolar transcripciones descartadas por tamaño 0 bytes

## Context

El pipeline de transcripción tiene un filtro de tamaño en `TranscriptionSubmitService::submit()` (línea ~55-62) que descarta como `dead` cualquier archivo menor a `min_file_size_bytes` (1MB). El caso típico es el grabador de radio que crea el MP3 al iniciar (0 bytes) y lo llena mientras el stream llega.

El problema: si el stream de red se congela (evento externo, ej. `streamtheworld.com` deja de enviar), el archivo queda en 0 bytes durante minutos. El filtro lo marca `dead` irreversiblemente. Cuando el stream se descongela, el archivo crece pero la transcripción ya se perdió.

El sistema ya tiene el mecanismo exacto para este caso: `markRequeueable()` (línea ~215) que pone la fila en `pending` con `requeue_after_at` futuro. El tick (`TranscriptionTickCommand` línea ~144-145) ya ignora las filas con `requeue_after_at` futuro y las reencola cuando el plazo vence. Este mecanismo se usa hoy para el rebote por tmpfs sin espacio.

## Goals / Non-Goals

**Goals:**
- Que un archivo de 0 bytes (stream congelado) se **reintente** en vez de marcarse `dead` irreversiblemente.
- Reutilizar `markRequeueable()` y el manejo de `requeue_after_at` del tick (cero lógica nueva de reintento).
- Evitar reintentos infinitos para archivos genuinamente corruptos.

**Non-Goals:**
- No cambiar `min_file_size_bytes`.
- No crear comandos de backfill nuevos.
- No tocar el grabador ffmpeg.

## Decisions

### D1. El descarte por tamaño pasa de `markDead` a `markRequeueable`

En `submit()`, cuando `filesize() < minSize`:
- Si `retries < max_retries` → `markRequeueable()` (pending + requeue_after_at futuro).
- Si `retries >= max_retries` → `markDead()` (comportamiento actual, archivo genuinamente corrupto).

**Por qué:** el reintento automático del tick espera a que el archivo crezca. El límite de `retries` evita que un archivo corrupto rebote infinitamente. Alternativa descartada: reintentar siempre — arriesga encolar basura para siempre.

### D2. Incrementar `retries` en el descarte por tamaño

`markRequeueable()` no incrementa `retries` (por diseño, para rebotes de pre-flight). Para el descarte por tamaño, **sí** hay que incrementarlo, porque es un intento de transcripción que falló por archivo incompleto.

**Implementación:** en `submit()`, antes de llamar `markRequeueable()`, incrementar `retries` en 1. Así, tras `max_retries` ciclos sin que el archivo crezca, pasa a `dead`.

**Por qué:** distingue "aplazamiento por infraestructura" (no cuenta) de "intento fallido de transcripción" (cuenta). El archivo de 0 bytes es un intento fallido.

### D3. No tocar el flujo de tmpfs

El rebote por tmpfs sin espacio (`markRequeueable` sin incrementar retries) se mantiene intacto — es un aplazamiento de infraestructura, no un fallo del audio.

## Risks / Trade-offs

- **Riesgo bajo.** Solo cambia el estado de salida del filtro de tamaño. El tick ya maneja `requeue_after_at`.
- **Trade-off:** un archivo que tarda > `max_retries * requeue_after_minutes` en crecer (stream congelado muy largo) terminará en `dead`. Aceptable: es el mismo límite que un ffmpeg que falla 3 veces.
- **No hay migración ni cambio de schema.**
