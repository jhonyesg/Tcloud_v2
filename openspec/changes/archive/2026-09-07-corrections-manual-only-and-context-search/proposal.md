## Why

La decisión del 2026-08-21 fue que el módulo de correcciones pase a **manual-only**: sin crons que consulten IA ni generen trabajo automático. Al auditar el estado real (2026-09-05) quedan dos crons automáticos **sin LLM pero igualmente programados** (`corrections:detect-english-residual --apply` y `corrections:cycle-suggestions`) que crean ~4.500 filas `needs_review`/día (pila actual: 119.405) y 2-5 reglas pending/día que nadie pidió. Además, la búsqueda de contexto ("Ejemplos") que abre el admin al editar una corrección hace `ILIKE '%…%'` sobre 49,8 M segmentos (13 GB) **sin índice trgm**, por lo que cancela por statement_timeout cada vez ("La búsqueda tardó demasiado"), y el modo "sensibles" de la pestaña de revisión corre un `whereExists` con `position()` sin acotar ni timeout que puede colgar PHP-FPM 300 s. El interruptor maestro `llm-correction.enabled` cae a default `true` (env ausente), contradiciendo la política defaults-off del 2026-08-25, y la skill `corrections-ai-suggest` documenta una frecuencia automática de 4 h que ya no existe y podría inducir su reactivación.

## What Changes

- **BREAKING (operativo, deseado)**: desprogramar `corrections:detect-english-residual` y `corrections:cycle-suggestions` en `app/routes/console.php`; quedan como comandos manuales bajo demanda, con guardrail de confirmación tipo `--confirm` para corridas con `--apply` masivas.
- Insertar `llm-correction.enabled = 0` en `system_settings` (defaults-off explícito e indelegable al env).
- Índice GIN `pg_trgm` sobre `(text, text_raw)` en `transcription_segments` para que la búsqueda de contexto y el modo sensibles usen index scan en vez de seq scan de 13 GB.
- `statement_timeout` acotado en `TranscriptionReviewService::list()` (modo sensibles) y cap temporal de la ventana.
- Actualizar la skill `.kilocode/skills/corrections-ai-suggest/SKILL.md` a la realidad manual-only (quitar "corre cada 4 horas", documentar toggles en OFF y cómo encenderlos solo bajo demanda del admin).
- No se purga la pila existente de 119.405 `needs_review` (queda congelada como archivo histórico; decisión aparte).

## Capabilities

### New Capabilities
- `corrections-manual-only-cadence`: la cadencia operativa del módulo correcciones — qué corre programado, qué no, y bajo qué condiciones un cron vuelve a habilitarse.

### Modified Capabilities
- `llm-correction-suggestion`: el requisito "AI-suggest runs every 4 hours via scheduler" cambia a "manual-only, sin schedule"; el master switch default pasa a OFF persistido en BD.
- `correcciones-review-srt-inline`: el modo sensibles de la lista de revisión debe responder acotado y con statement_timeout (no colgar el worker).

## Impact

- `app/routes/console.php` (2 schedules fuera), `app/app/Console/Commands/{DetectEnglishResidualCommand,CycleSuggestionsCommand}.php` (guardrail manual).
- `app/app/Services/Ia/TranscriptionReviewService.php` (timeout + ventana).
- Migración nueva: índice GIN trgm sobre `transcription_segments` (tabla 13 GB — crear con `maintenance_work_mem` alto, fuera de picos; CONCURRENTLY si se prefiere sin lock).
- `system_settings`: fila `llm-correction.enabled=0`.
- `.kilocode/skills/corrections-ai-suggest/SKILL.md` (documentación).
- Sin cambios de esquema de datos de usuario; la pila `needs_review` no se toca.

## Non-goals

- No purgar ni re-estadificar la pila de `transcription_reviews.needs_review`.
- No tocar el pipeline del transcriptor (donde vive la causa raíz del inglés residual).
- No reactivar ningún LLM ni cron de IA; no agregar kill-switches nuevos para los crons rule-based (desprogramarlos basta).
- No rediseñar el triage pendiente ni la UI de moderación.