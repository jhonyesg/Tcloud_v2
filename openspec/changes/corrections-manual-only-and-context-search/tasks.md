# Tasks: corrections-manual-only-and-context-search

## 1. Desprogramar crons rule-based (cadencia manual-only)

- [ ] 1.1 En `app/routes/console.php`: comentar con fecha/razón los `Schedule::command('corrections:detect-english-residual ...')` y `Schedule::command('corrections:cycle-suggestions ...')` siguiendo el patrón del bloque histórico 2026-08-11.
- [ ] 1.2 Añadir guardrail `--confirm` a `DetectEnglishResidualCommand`: si `--apply` y ventana > 24 h sin `--confirm`, degradar a dry-run con aviso.
- [ ] 1.3 Añadir guardrail `--confirm` a `CycleSuggestionsCommand`: si escritura real (sin `--dry-run`) y ventana > 24 h sin `--confirm`, degradar a dry-run con aviso.
- [ ] 1.4 Verificar con `php artisan schedule:list` que ningún corrections:* programado persiste (quedan solo cleanup-undo-log y triage-pending --dry-run, sin escritura de negocio).

## 2. Master switch persistido en BD

- [ ] 2.1 Migración idempotente que inserte `llm-correction.enabled = 0` en `system_settings` si la fila no existe (patrón SystemSetting).
- [ ] 2.2 Ejecutar la migración y verificar `SELECT value FROM system_settings WHERE key='llm-correction.enabled'` = 0.

## 3. Índices GIN trgm para búsqueda de contexto

- [ ] 3.1 Migración que crea `gin (text gin_trgm_ops)` y `gin (text_raw gin_trgm_ops)` sobre `transcription_segments` con `maintenance_work_mem` elevado en la sesión; documentar en el docblock la alternativa CONCURRENTLY manual.
- [ ] 3.2 Verificar con `EXPLAIN` que `CorrectionContextFinder::search()` (ILIKE sobre text/text_raw) usa Bitmap/Index Scan y responde < 10 s para un probe real ≥ 3 chars.
- [ ] 3.3 Abrir "Contexto" de una corrección pendiente en `/ia/correcciones` y confirmar que ya no aparece el estado timeout.

## 4. Modo sensibles acotado y con timeout

- [ ] 4.1 En `TranscriptionReviewService::list()`: resolver N candidatas (latest done, limit 10) primero; correr filtro/conteo sensibles dentro de transacción con `SET LOCAL statement_timeout` (`config('corrections.review_sensitive.timeout_ms', 10000)`).
- [ ] 4.2 Capturar QueryException de timeout: degradar esa pieza (conteo 0, `degraded: true` en payload) y responder 200; sin 504 posible.
- [ ] 4.3 Verificar con un curl al endpoint `?mode=sensitive` que responde en tiempo acotado y que `mode=latest/completed` siguen intactos.

## 5. Actualizar skill corrections-ai-suggest

- [ ] 5.1 Reescribir `.kilocode/skills/corrections-ai-suggest/SKILL.md`: quitar la sección de frecuencia automática (4 h), documentar que no hay cron desde 2026-08-11 (ratificado 2026-09-05), master switch OFF persistido en BD, y ejecución solo bajo pedido explícito del admin con dry-run primero.

## 6. Validación final

- [ ] 6.1 Ejecutar suite de tests del proyecto (unitarios de correcciones/transcription-review) y `openspec validate --change corrections-manual-only-and-context-search`.
- [ ] 6.2 Confirmar en `laravel.log` que no hay errores nuevos tras deploy y que `schedule:list` muestra el estado final esperado.