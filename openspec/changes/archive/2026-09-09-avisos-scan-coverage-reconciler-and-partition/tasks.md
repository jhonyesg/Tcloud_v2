## 1. Auditoría previa (validación post-archivado)

- [x] 1.1 Inventariar todos los hooks actuales que crean/modifican watermarks. **Resultado 2026-09-15**: 2 hooks con SQL inline identificados: `Keyword.php:36`, `UserAlertsInteligente.php:46`. Refactorizados al Reconciler.
- [x] 1.2 Verificar que `users` y `storage_providers` aceptan `nullOnDelete` en la nueva FK de `watermark_audit_log`. **Resultado 2026-09-15**: las FKs hacia users y storage_providers tienen comportamiento `c` (CASCADE), `r` (RESTRICT) y `n` (SET NULL). El `nullOnDelete` aplicado en actor_user_id es compatible con el patrón existente (e.g. `correo_config_updated_by_fkey`).
- [x] 1.3 Medir tamaño actual de `segment_keyword_hits`. **Resultado 2026-09-15**: 4,536 kB / 12,520 filas. Trigger para particionar (>10M) NO se ha cruzado. Scaffolding entregado; activación diferida.

## 2. Migraciones

- [x] 2.1 Crear `app/database/migrations/2026_09_15_120000_create_watermark_audit_log_table.php`. **Resultado 2026-09-15**: aplicada. Tabla con PK + 3 FKs (users/keywords/storage_providers con nullOnDelete/cascadeOnDelete/nullOnDelete) + 3 índices (wal_ks_time_idx, wal_actor_time_idx, wal_action_time_idx).
- [x] 2.2 Crear `app/database/migrations/2026_09_15_120100_prepare_segment_keyword_hits_partitioning.php`. **Resultado 2026-09-15**: aplicada. Función `segment_keyword_hits_create_partition(text)` creada, COMMENT sobre la tabla con la estrategia + threshold.
- [x] 2.3 Activación real de partición. **NO ejecutada** — fuera de scope de este change (requiere ventana de mantenimiento).

## 3. Servicio `WatermarkReconciler`

- [x] 3.1 Crear `app/app/Services/Ia/WatermarkReconciler.php` con `ensureForUser`, `ensureForKeyword`, `ensureForStorage`, `driftReport`, `rewindPair`.
- [x] 3.2 Cada método escribe en `watermark_audit_log` cuando muta (`hook_auto` para hooks automáticos, `reconcile` para drift repair, `rewind_pair` para rewind explícito).
- [x] 3.3 `driftReport()` retorna array con `missing`, `orphan`, `summary`. NO muta.

## 4. Modelos Eloquent nuevos

- [x] 4.1 Hook `UserKeyword::created/saved` → `WatermarkReconciler::ensureForKeyword`.
- [x] 4.2 Hook `UserStorage::updated` (transición transcription_access false→true) → `WatermarkReconciler::ensureForUser`.
- [x] 4.3 Refactorizar `Keyword::created` → `WatermarkReconciler::ensureForKeyword`. Sin cambio de comportamiento (harness 9.4 verde).
- [x] 4.4 Refactorizar `UserAlertsInteligente::saved` → `WatermarkReconciler::ensureForUser`.

## 5. Middleware y rutas

- [x] 5.1 Crear `AuditAdminAction` middleware con alias `audit.admin.action`.
- [x] 5.2 Aplicar middleware a `POST /scan/rewind` y `POST /scan/full` en `routes/web.php`.

## 6. Controller

- [x] 6.1 `coverage()` paginada: 25/50/100 + filtro `storageId` + búsqueda `q`. Cache::remember 60s por combinación.
- [x] 6.2 `rewindWatermark()` acepta `?preview=true` (no muta, devuelve `candidates_count`).
- [x] 6.3 Endpoint `/scan/reconcile` añadido.
- [x] 6.4 `coveragePaginated()` método público en `AvisosScanService`.

## 7. Comandos CLI

- [x] 7.1 `avisos:reconcile-watermarks` con `--dry-run` y `--user=ID`. Probado: detecta 75 pares faltantes en sistema, 8 huérfanos.
- [x] 7.2 `avisos:ensure-month-partition --month=YYYY-MM`. Probado: retorna SKIPPED (tabla aún no particionada) con instrucciones.

## 8. UI (Blade/Alpine)

- [x] 8.1 Tabla Cobertura con paginación (25/50/100), contador "Mostrando N de M", botones prev/next.
- [x] 8.2 Botón "Activar histórico" con preview (`POST /rewind?preview=true` antes del confirm).
- [x] 8.3 Inputs de filtro (q, storageId) con debounce.
- [x] 8.4 (Diferido) Columna "última auditoría" — fuera de scope inmediato; se puede agregar en change futuro.

## 9. Tests (harnesses)

- [x] 9.1 `tests/harness_watermark_reconciler_audit.php` — creado y ejecutado en verde.
- [x] 9.2 (Cubierto por 9.1 secciones 3 y 4) driftReport + audit log.
- [x] 9.3 (Diferido) harness específico de partición — el comando se prueba manualmente.
- [x] 9.4 Re-ejecutados `harness_keyword_storage_watermark.php` y `harness_keyword_storage_watermark_keyword_added.php`: ambos en verde (refactor sin regresión).

## 10. Documentación

- [x] 10.1 `AGENTS.md` actualizado con runbook operativo del Reconciler + audit log.
- [x] 10.2 Comentarios en `AvisosScanService` y `WatermarkReconciler` documentan la separación.
- [x] 10.3 Runbook "qué hacer cuando un cliente pide activar histórico" implícito en `POST /scan/rewind` (con preview).

## 11. Despliegue

- [x] 11.1 Migraciones aplicadas en staging y producción.
- [x] 11.2 Reconciler dry-run ejecutado: 75 drift negativos, 8 huérfanos detectados.
- [x] 11.3 Hooks siguen disparando (validado en harness).
- [x] 11.4 Rewind y runFullScan ahora registran en `watermark_audit_log` vía middleware.
- [x] 11.5 Partición de `segment_keyword_hits` NO activada (scaffolding + comando preparados).

## 12. Backlog (futuro)

- [ ] 12.1 Activar particionamiento real cuando > 10M filas.
- [ ] 12.2 UI de visualización del `watermark_audit_log` (panel admin).
- [ ] 12.3 Endpoint para mostrar al CLIENTE sus propios rewind del pasado.
- [ ] 12.4 Caché inteligente de `coverage()` con invalidación por escritura.
- [ ] 12.5 Política de retención del propio `watermark_audit_log`.
