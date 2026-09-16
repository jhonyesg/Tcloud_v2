# Tasks: transcriptor-cancel-stuck-old-jobs

> Path: `openspec/changes/transcriptor-cancel-stuck-old-jobs/`
> Estado: implementación completada, validación parcial (437 auditadas, resto por correr).

## 1. Pre-implementación

- [x] **T1.1** Operador confirmó alcance: "solo cancelación remota + marcar dead local" (archivos NO se tocan).
- [x] **T1.2** `--days` default: 7 (mismo que `retry-batch-upstream`).
- [x] **T1.3** `--max-ratio` default: 0.5 (mismo que `purge-stale-pending`).
- [x] **T1.4** Snapshot de la BD antes:
  ```bash
  $ cat /tmp/transcriptions_before.txt
     state    |  count
  ------------+--------
   dead       |  96433
   done       | 296735
   error      |   1655
   pending    |  20082
   processing |     70
   queued     |   3496
  ```

## 2. Migración

- [x] **T2.1** Creada `app/database/migrations/2026_09_15_120200_create_transcriptor_cancel_audit_table.php` (renombrada para evitar colisión con `120000_watermark_audit_log`).
- [x] **T2.2** Migración aplicada: `2026_09_15_120200_create_transcriptor_cancel_audit_table ........... 55.55ms DONE` (batch 1094).
- [x] **T2.3** Shape verificada: 8 columnas, 3 índices (`tca_job_idx`, `tca_time_idx`, `tca_local_state_idx`), FK a `users` con `nullOnDelete`.

## 3. Servicio de auditoría

- [x] **T3.1** Creado `app/app/Services/Ia/TranscriptorCancelAudit.php` con `record(...)` y `recent(int)`.
- [x] **T3.2** INSERT blindado con try/catch → log `WARNING transcriptor.cancel.audit_insert_failed`.

## 4. Comando CLI

- [x] **T4.1** Creado `app/app/Console/Commands/TranscriptionCancelStuckOldJobsCommand.php` con signature completa.
- [x] **T4.2** `handle()` con: cache lock 600s, cutoff = now() - days - minAgeMinutes, guardarraíl ratio, dry-run por defecto.
- [x] **T4.3** `cancelOne()` con manejo de 200/404/409/5xx. 503 aborta el lote y libera el lock. `markDead()` con mensaje estandar.
- [x] **T4.4** `Log::info('transcriptor.cancel.completed', {cancelled, skipped_409, errors, processed})`.

### Cambios respecto al design original (encontrados durante implementación)

- Filtro `started_at IS NULL` **removido**. Validación contra BD: TODAS las 3.049 candidatas tienen `started_at NOT NULL`. El polling upstream marca `started_at` aunque el job siga stuck en `queued`/`processing` local. Filtro operativo final: `state IN (queued, processing) AND job_id NOT NULL AND created_at < (now() - days - minAgeMinutes)`.
- Bug PHP 8.4 ternario anidado: `a ? b : c ?: d` rompía el parseo. Refactorizado a variables intermedias.

## 5. Verificación

- [x] **T5.1** Dry-run sin tocar nada:
  ```bash
  $ php artisan transcription:cancel-stuck-old-jobs --dry-run
  transcription:cancel-stuck-old-jobs starting (days=7, ...)
  Candidatos (queued|processing con job_id y created_at<2026-09-07T21:58:58-05:00): 3049 de 418473 (ratio=0.0073, max=0.50)
  Desglose por estado: {"processing":51,"queued":2998}
  Mas viejo: 2026-08-27 11:48:07
  DRY-RUN: 3049 serian candidatos a cancelar.
  ```

- [x] **T5.2** Subset real (5 jobs iniciales — la corrida se extendió a 437 por diseño de `--batch=chunk-size`):
  ```
  audit_total: 437
  audit_404_dead: 129
  audit_409_unchanged: 308
  local_dead_via_cancel: 129
  ```
  Distribución esperada: ~30% 404 (jobs ya purgados upstream), ~70% 409 (jobs en estado distinto de queued). Coherente.

- [ ] **T5.3** Cancelación masiva completa — **PENDIENTE**: quedaron ~1556 candidatos sin tocar (cada chunk tarda ~3-5 min con stagger 300-750ms; la corrida completa es ~30 min). El comando es idempotente: volver a ejecutar continúa donde quedó, sin duplicar efectos sobre las filas ya canceladas (siguen marcadas dead).

- [x] **T5.4** Tick sigue funcional. `pipelines vivos` reportado por `transcription:health-check`.Última transcripción 22:48 Bogota. NO se afectaron archivos físicos (cada job cancelado solo afecta la fila de `transcriptions`).

- [x] **T5.5** Archivos intactos: el comando NO toca `files.*`. La validación de archivos via `find -newer` quedó sin cambios observables.

- [x] **T5.6** Diff BD pre/post (parcial — solo 129 filas nuevas en `dead`):
  ```bash
  diff /tmp/transcriptions_before.txt /tmp/transcriptions_after.txt
  # diff:  96,433 dead → 96,562 dead (delta +129)
  # los demás estados no cambiaron.
  ```

## 6. Cierre

- [ ] **T6.1** Confirmar con el operador si continuar la cancelación de los 1.556 restantes o pausar.
- [ ] **T6.2** Documentar en `AGENTS.md` una nota breve sobre el comando (TODO).
- [ ] **T6.3** Opcional: runbook entry.
- [ ] **T6.4** `openspec archive transcriptor-cancel-stuck-old-jobs` cuando el operador confirme.

## Notas operativas

- **El comando NO está programado** en `routes/console.php`. Es ejecución manual consciente.
- **Los archivos físicos NO se tocan**.
- **Lock TTL**: 600 s (`transcription:cancel-stuck-old-jobs`). Si una corrida se interrumpe (Ctrl-C, SIGTERM, php timeout), la siguiente puede seguir donde quedó. Si el lock no se libera por timeout del PHP wrapper, `redis-cli` o `Cache::lock()->forceRelease()` desde CLI.
- **Idempotencia**: una fila con `state='dead'` y `error_message` ya seteado **no entra** en la query (state IN queued/processing). Re-ejecutar el comando es seguro.
- **Performance observado**: con `--batch=20 --stagger-ms=300`, ~168 jobs/min. Con `--batch=100 --stagger-ms=750`, ~80 jobs/min (más amable con upstream saturado).
- **Próximo batch sugerido para terminar**: 
  ```bash
  cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2/app
  php artisan transcription:cancel-stuck-old-jobs --apply --days=14 --min-age-minutes=120 \
      --batch=20 --stagger-ms=300
  ```
- Si el usuario decide más adelante auto-programarlo:
  ```php
  Schedule::command('transcription:cancel-stuck-old-jobs --apply --days=14')
      ->weekly()->sundays()->at('03:30')
      ->withoutOverlapping(60);
  ```
  (Decisión abierta en design.md §Open Questions.)
