# Tasks: Reencolar transcripciones descartadas por tamaño 0 bytes

## 1. Backend: `TranscriptionSubmitService::submit()` — descarte por tamaño reencolable

- [ ] En `app/app/Services/Ia/TranscriptionSubmitService.php`, en el bloque del filtro de tamaño (línea ~55-62):
  - Si `$size < $minSize`:
    - Si `(int) $transcription->retries < max_retries`:
      - Incrementar `retries` en 1.
      - Llamar `markRequeueable($transcription, "Archivo incompleto ({$size} bytes < {$minSize} minimo). Reintento en el proximo ciclo.")`.
      - Retornar `['ok' => false, 'error' => "Archivo < {$minSize} bytes", 'requeueable' => true]`.
    - Si no (retries agotados): mantener `markDead(...)` actual.

## 2. Verificación

- [ ] Con un archivo de 0 bytes y `retries=0`: la `Transcription` queda en `pending` con `requeue_after_at` futuro y `retries=1` (NO `dead`).
- [ ] El tick ignora la fila hasta que vence `requeue_after_at`.
- [ ] Si el archivo crece antes del reintento, se transcribe normalmente.
- [ ] Tras `max_retries` intentos sin que el archivo crezca, pasa a `dead`.
- [ ] El rebote por tmpfs sin espacio sigue sin incrementar `retries`.

## 3. Archivar

- [ ] Mover a `archive/2026-08-15-2026-08-15-transcription-zero-byte-requeue/`.
