# Spec: Reencolar transcripciones descartadas por tamaño 0 bytes

## MODIFIED Requirements

### Requirement: Archivo menor al mínimo se reintenta, no se descarta

El sistema SHALL, cuando un archivo de audio tiene un tamaño menor a `min_file_size_bytes` al momento del envío, **reencolar** la transcripción en vez de marcarla `dead`, siempre que el contador `retries` sea menor a `max_retries`.

#### Scenario: Stream congelado, archivo en 0 bytes
- **WHEN** el grabador crea un MP3 de 0 bytes y el stream de red se congela (el archivo no crece)
- **AND** el pipeline intenta transcribirlo y `filesize() < min_file_size_bytes`
- **AND** `retries < max_retries`
- **THEN** la `Transcription` se marca en `state=pending` con `requeue_after_at` futuro
- **AND** `retries` se incrementa en 1
- **AND** el tick la ignora hasta que venza `requeue_after_at`, y luego la reintenta

#### Scenario: Stream se descongela antes del reintento
- **WHEN** el archivo crece por encima de `min_file_size_bytes` antes del siguiente intento
- **THEN** el reintento transcribe el archivo normalmente (state → queued → done)

#### Scenario: Archivo genuinamente corrupto (0 bytes persistente)
- **WHEN** el archivo permanece menor a `min_file_size_bytes` tras `max_retries` intentos
- **THEN** la `Transcription` se marca `dead` con el mensaje de tamaño (comportamiento actual)

#### Scenario: Reintento no cuenta como aplazamiento de infraestructura
- **WHEN** el descarte por tamaño incrementa `retries`
- **THEN** el rebote por tmpfs sin espacio (`markRequeueable` de pre-flight) NO incrementa `retries` (comportamiento existente, no roto)
