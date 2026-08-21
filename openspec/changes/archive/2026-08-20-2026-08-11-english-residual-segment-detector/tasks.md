# Tasks: Detector de segmentos con inglés residual

## 1. Servicios base

- [ ] Crear `app/app/Services/Ia/EnglishResidualSegmentDetector.php` con la firma documentada en design.md.
- [ ] Implementar `scoreSegment(string $text): array` con tokenización y jerarquía de clasificación (EN_FUNCTIONS → ES_STOPWORDS → acento → unknown).
- [ ] Implementar `scoreTranscription(int $transcriptionId): array` que itere segments y agregue.
- [ ] Implementar `findFlaggedTranscriptions(float $threshold, int $daysBack): array` con prefiltro SQL (`text ~* english-marker`).
- [ ] Implementar `flagForReview(int $transcriptionId, int $reviewerId, array $score): TranscriptionReview` con `updateOrCreate` y respeto a status humano (`correct`/`ignored`).
- [ ] Cargar listas `en_functions` y `es_stopwords` desde `config/corrections.php` (key `english_residual`).

## 2. CLI command

- [ ] Crear `app/app/Console/Commands/DetectEnglishResidualCommand.php` con signature `--days --threshold --id --apply --json`.
- [ ] Default `--dry-run` (sin `--apply`).
- [ ] Soportar `--id=X` con multi-valor para escanear transcripciones específicas.
- [ ] Output en tabla (`title`, `transcription_id`, `score`, `flagged_segments`, `finished_at`) por defecto.
- [ ] Output JSON con `--json` para integración.
- [ ] Reportar `skipped_manual` cuando status preexistente es `correct`/`ignored`.

## 3. Configuración

- [ ] `app/config/corrections.php`: agregar sección `english_residual` con `threshold`, `en_functions`, `es_stopwords` si el archivo existe; si no, crearlo.
- [ ] Reusar `EnEsMixMiner::EN_FUNCTIONS` para no duplicar la lista.
- [ ] Documentar la env var `EN_RESIDUAL_THRESHOLD` en README o comments.

## 4. Pruebas unitarias

- [ ] `tests/Feature/EnglishResidualSegmentDetectorTest.php` con casos:
  - Segmento ES puro → score ≈ 0.
  - Segmento EN puro → score ≈ 1.0.
  - Spanglish mixto (score 0.4-0.6) → respeta threshold.
  - Token con acento → clasifica como ES.
  - Token en `EnEsMixMiner::EN_FUNCTIONS` → clasifica como EN.
  - `flagForReview` no pisa status `correct`.
  - `flagForReview` no pisa status `ignored`.
  - `flagForReview` es idempotente (mismo notes, no re-escribe).
- [ ] Test integrado: ejecutar `--apply` sobre transcripción de prueba y verificar fila en `transcription_reviews`.

## 5. Dry-run contra el corpus real

- [ ] `php artisan corrections:detect-english-residual --days=1 --threshold=0.4` (dry-run).
- [ ] Verificar que la lista incluye `#164215 camarafm`, `#164221 caracol_atlantico`, `#164217 caracol_valle`, `#164219 caracol_antioquia`, `#165445 telepacifico`, `#165436 unradio` (los identificados en auditoría).
- [ ] Verificar que NO incluye `#165433 minutodedios`, `#165435 sol`, `#164214 tolima`, etc. (los limpios).
- [ ] Reportar métricas: total flagged, distribución por score, top 10 con más segmentos flagged.

## 6. Aplicación controlada

- [ ] `php artisan corrections:detect-english-residual --days=1 --threshold=0.4 --apply`.
- [ ] `SELECT COUNT(*) FROM transcription_reviews WHERE status='needs_review' AND notes LIKE 'english_residual:%'` muestra N nuevos.
- [ ] UI `/ia/correcciones` → tab "Revisión" → "Últimas 10" muestra las nuevas con badge ámbar.
- [ ] Abrir una de las marcadas y leer la nota para confirmar formato legible.

## 7. Verificación de no-regresión

- [ ] `EnEsMixMiner` no fue modificado.
- [ ] `ContextShiftAuditor` no fue modificado.
- [ ] `TranscriptionReviewService` no fue modificado.
- [ ] `CorrectionService` no fue modificado.
- [ ] Ningún `text` de `transcription_segments` fue alterado (solo `transcription_reviews` recibe upserts).
- [ ] Las 12 reglas de la ronda 2 no fueron tocadas.

## 8. Operación recurrente

- [ ] Documentar el uso del comando en un README/`AGENTS.md` ligero (qué hace, cuándo correrlo, qué riesgos).
- [ ] Decidir si se agrega cron automático (recomendación: NO, dejar manual).
- [ ] Establecer cadencia sugerida: 1 vez al día o después de cada pico de transcripciones, según criterio del operador.

## 9. Métricas de éxito (1 semana después)

- [ ] `SELECT COUNT(*) FROM transcription_reviews WHERE status='needs_review' AND reviewed_at > NOW() - INTERVAL '7 days'` → número de transcripciones marcadas por el detector.
- [ ] Revisar manualmente 5 transcripciones marcadas y categorizar: falso positivo, canción en inglés, entrevista bilingüe, error de transcripción real.
- [ ] Si los falsos positivos superan 30%, ajustar threshold a 0.5 o extender `es_stopwords` con más términos.
- [ ] Si las marcadas correctamente bajan el "inglés residual" en producción (medido por re-ejecución del scan), considerar programar cron automático.
