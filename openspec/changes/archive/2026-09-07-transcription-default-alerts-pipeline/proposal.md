# Proposal — Toda transcripción nueva entra por defecto al pipeline de avisos

## Why

`transcription:scan-and-submit` crea filas `transcriptions` con `generate_alerts`
a partir del flag `--alerts` de la línea de comandos (default ausente → false).
El tick automático (`transcription:tick`, cada 2 min) llama al sub-comando SIN
el flag `--alerts`; el operador que lanza un lote manual desde la UI sí lo
pasa correctamente (ver comentario histórico en
`ApiTranscriptorController.php:1106-1110` — *"generate_alerts se validaba
arriba pero nunca llegaba al comando"*).

Resultado en producción desde ~2026-09-05 05:00 UTC: la práctica totalidad de
transcripciones creadas por el tick nacen con `generate_alerts=false`. El
cron `avisos:scan` (cada 5 min) filtra por `generate_alerts=true`
(`AvisosScanService::selectCandidates`), por lo que el matching de keywords
deja de procesar transcripciones nuevas en cuanto el tick toma el control.

El modelo de avisos es de tres capas con responsabilidades separadas:

1. **Ingesta** — toda transcripción terminada debe entrar al matching global.
2. **Matching global** — `KeywordMatcher` escanea cada transcripción contra el
   conjunto de keywords DISTINCT de todos los usuarios con acceso al storage;
   produce `segment_keyword_hits` compartidos (UNIQUE transcripción+segmento+keyword).
3. **Fanout per-user** — `avisos:deliver-alerts` crea `alert_deliveries` solo
   para los usuarios cuyo `user_keyword` coincide y `user_alerts_inteligentes.enabled=true`.

El filtrado por cliente es responsabilidad exclusiva de la capa 3. Excluir
transcripciones en la capa 1 rompe el invariante: una keyword de un cliente
no se evalúa nunca para esa transcripción, sin que el cliente ni el admin
puedan saberlo. El flag `generate_alerts` debe interpretarse como opt-out
explícito del operador, no como opt-in accidental.

Síntomas visibles:
- `avisos_scan_runs` últimas 20+ corridas: `transcriptions_scanned=0`, `hits_new=0`,
  `status=success` (silenciosamente inútil desde el 2026-09-06 21:29 UTC).
- `segment_keyword_hits.matched_at` no crece desde 2026-09-06 23:52:45.
- 1 810 transcripciones terminadas el 2026-09-07 nacieron con `generate_alerts=false`.

## What Changes

- **Default del flag en `ScanAndSubmitCommand`**: `--alerts` se considera activo
  cuando el caller no lo pasa (cualquier invocación que omita el flag hereda
  `true`). El opt-out explícito sigue siendo válido con `--no-alerts`.
- **Tick automático** (`TranscriptionTickCommand`): pasar `'--alerts' => true`
  explícitamente al `call('transcription:scan-and-submit', ...)` para que la
  intención sea legible en diffs y no dependa del default del sub-comando.
- **Invariante documentado** en `Transcription` (docblock del modelo): toda
  transcripción nueva entra al matching global de menciones salvo opt-out
  explícito del operador. El filtrado por cliente ocurre en `avisos:deliver-alerts`.
- **Backfill**: tras el fix, `avisos:scan --no-window --limit=200` repetido
  (o `avisos:scan-run` background runner) drena las transcripciones con
  `generate_alerts=false` que ya deberían estar en el matching.
- **Spec nuevo** `transcription-default-alerts-pipeline`: codifica el
  invariante, los escenarios de opt-out y el contrato del default `--alerts`.

## Capabilities

### New Capabilities

- `transcription-default-alerts-pipeline`: toda transcripción nueva entra al
  matching global de menciones; el opt-out por `generate_alerts=false` es
  responsabilidad exclusiva del operador y debe ser trazable.

## Impact

- `app/app/Console/Commands/ScanAndSubmitCommand.php`: cambiar la lectura de
  `--alerts` de `(bool) $this->option('alerts')` a un default-true
  (mantiene la firma del flag y respeta `--no-alerts`).
- `app/app/Console/Commands/TranscriptionTickCommand.php`: agregar
  `'--alerts' => true` al `call('transcription:scan-and-submit', ...)`.
- `app/app/Models/Transcription.php`: docblock del campo `generate_alerts`
  aclarando el invariante.
- Backfill operativo (no toca código): correr `avisos:scan --no-window` con
  el runner background hasta drenar las transcripciones rezagadas.
- Spec nuevo bajo `openspec/specs/transcription-default-alerts-pipeline/`.

## Non-goals

- No se introduce un sistema de opt-out por calidad/duración/idioma de la
  transcripción. El flag sigue siendo binario.
- No se cambia la semántica de `avisos:scan`, `KeywordMatcher` ni
  `avisos:deliver-alerts`. El fix vive aguas arriba de ellos.
- No se eliminan las transcripciones con `generate_alerts=false` ya
  persistidas: se re-procesan en el backfill, no se borran.
- No se cambia el contrato del manual UI (`generate_alerts` checkbox
  sigue funcionando como opt-out visible).
