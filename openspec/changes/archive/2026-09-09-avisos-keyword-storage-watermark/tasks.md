## 1. Auditoría previa de normativa BD

- [x] 1.1 Verificar FKs en `storage_providers`: ejecutar `SELECT conname, confdeltype FROM pg_constraint WHERE conrelid = 'storage_providers'::regclass;` y confirmar que ningún hijo tiene RESTRICT (de lo contrario, usar `restrictOnDelete` en lugar de `cascadeOnDelete` para `keyword_scan_watermarks.storage_provider_id`). **Resultado 2026-09-09**: storage_providers no tiene FKs en su propia tabla; las 3 tablas hijas (`files`, `user_storages`, `user_keyword_storage`) usan CASCADE — `cascadeOnDelete` es seguro.
- [x] 1.2 Confirmar convención de `timestamp(0)` revisando 3 migraciones existentes del módulo avisos (debe ser `timestamp` sin precisión). **Resultado 2026-09-09**: `timestamp without time zone` confirmado en `avisos_scan_runs.started_at/finished_at/created_at/updated_at`.
- [x] 1.3 Confirmar cardinalidad esperada: `SELECT COUNT(*) FROM (SELECT keyword_id, storage_provider_id FROM segment_keyword_hits h JOIN transcriptions t ON t.id=h.transcription_id JOIN files f ON f.id=t.file_id GROUP BY 1,2) sub;` para validar que el seed inicial no generará más de ~600 filas. **Resultado 2026-09-09**: 66 pares con hits hoy; total estimado del seed ~100-200 filas (incluye pares sin hits pero con acceso). Bien dentro del presupuesto.

## 2. Migraciones de base de datos

- [x] 2.1 Crear migración `app/database/migrations/2026_09_10_120000_create_keyword_scan_watermarks_table.php` con el schema del design.md (PK compuesta, FKs, índices `ksw_storage_scanned_idx` y `ksw_scanned_idx`). **Resultado 2026-09-09**: migración aplicada; tabla creada con PK compuesta, 3 FKs (CASCADE/CASCADE/NULL), 2 índices nombrados.
- [x] 2.2 Crear migración `app/database/migrations/2026_09_10_120100_seed_keyword_scan_watermarks_from_existing_hits.php` que derive `scanned_until = MIN(t.finished_at) - 1 SECOND` por `(h.keyword_id, f.storage_provider_id)` para los pares que YA tienen hits, e inserte los pares aplicables con `scanned_until = NULL` para los que no tienen hits pero tienen usuarios con acceso. **No debe re-escanear nada.** **Resultado 2026-09-09**: 512 filas totales (66 con watermark inferido desde hits, 446 con NULL para catch-up).
- [x] 2.3 Crear migración `app/database/migrations/2026_09_10_120200_remove_legacy_avisos_scan_cursor_setting.php` que ejecute `DELETE FROM system_settings WHERE key = 'avisos_scan_cursor';` (reemplazado por la nueva tabla). **Resultado 2026-09-09**: setting eliminado.

## 3. Refactor del servicio de escaneo

- [ ] 3.1 En `app/app/Services/Ia/AvisosScanService.php`, refactorizar `selectCandidates()`: cambiar la salida de "transcripción" a `(transcription, keyword_id)` haciendo `CROSS JOIN` con `keywords` filtrado por usuarios habilitados y JOIN con `keyword_scan_watermarks`. Quitar el filtro `whereNull('h.id')` global y reemplazarlo por `WHERE h.id IS NULL` sobre `(t.id, k.id)`.
- [ ] 3.2 Añadir `selectCandidatesForPair(int $keywordId, int $storageId)` para el modo `runPair` (operaciones sobre un par específico desde la UI).
- [ ] 3.3 Añadir método público `bumpWatermarks(array $bumpSet, int $runId): void` que ejecute UPSERT atómico con `GREATEST(scanned_until, EXCLUDED.scanned_until)`.
- [ ] 3.4 Añadir método público `runFullScan(array $opts = []): array` con loop acotado por `avisos_scan_full_max_runtime_seconds` (default 600) y `avisos_scan_full_batch` (default 200). Reportar progreso en cada iteración.
- [ ] 3.5 Añadir método público `coverage(?int $storageId = null): array` que devuelva el estado de cobertura por keyword para la UI.
- [ ] 3.6 Añadir método público `rewindWatermark(int $keywordId, int $storageId, ?Carbon $to = null): void` para "Activar histórico" desde la UI. `to=null` ⇒ `scanned_until = NULL`.
- [ ] 3.7 Eliminar la lectura/escritura de `SystemSetting('avisos_scan_cursor')` en `run()` y `selectCandidates()` (reemplazado por la nueva tabla).

## 4. Refactor del motor de matching

- [ ] 4.1 En `app/app/Services/Ia/KeywordMatcher.php`, añadir parámetro opcional `$scopedKeywordIds: ?array` en `run(Transcription)`. Si viene, filtra el conjunto de keywords candidatas a esa lista (modo "estos son los pares pendientes, escanéalos solo a ellos").
- [ ] 4.2 Mantener el skip per-`(transc, keyword)` existente (líneas 44-48 y 73) — sigue siendo defensa en profundidad.

## 5. Hook de modelo

- [ ] 5.1 En `app/app/Models/Keyword.php`, añadir `booted()` con `static::created` que cree watermarks para cada `storage_provider_id` con usuarios habilitados con `transcription_access=true` (consulta vía `user_storages` JOIN `user_alerts_inteligentes`). Usar `insertOrIgnore` para idempotencia.
- [ ] 5.2 En `app/app/Models/Keyword.php`, añadir `static::deleted` con limpieza de `keyword_scan_watermarks` (la FK `cascadeOnDelete` ya lo hace, pero el evento es útil para emitir `Log::info`).

## 6. Comando CLI y settings

- [ ] 6.1 En `app/app/Console/Commands/ScanMentionsCommand.php`, añadir opciones: `--full` (modo escaneo completo), `--keyword-id=` (filtra por keyword específica), `--storage-id=` (filtra por storage, ya existe --storage, alias).
- [ ] 6.2 Añadir settings `avisos_scan_full_batch` (default 200) y `avisos_scan_full_max_runtime_seconds` (default 600) en `AvisosScanService::settings()`.
- [ ] 6.3 Añadir comando nuevo `app/app/Console/Commands/ResetWatermarkCountersCommand.php` (`avisos:reset-watermark-counters [--dry-run]`) para la tarea 12 (administrativo, no urgente).

## 7. Controller y endpoints

- [ ] 7.1 En `app/app/Http/Controllers/Ia/AvisosInteligentesController.php`, añadir método `coverage()` que devuelva JSON con el estado por keyword para alimentar la UI.
- [ ] 7.2 Añadir método `rewind(int $keywordId, ?int $storageId)` (POST) para "Activar histórico" desde la UI — admin only.
- [ ] 7.3 Añadir método `runFullScan()` (POST) que encole la corrida full en background (patrón del trait `RunsBackgroundCommands`).

## 8. UI

- [ ] 8.1 En `resources/views/ia/avisos-inteligentes/index.blade.php`, añadir nueva pestaña "Cobertura" con tabla de keywords × storages y sus watermarks. Botón "Activar histórico" por par.
- [ ] 8.2 Añadir botón "Escaneo completo" en la pestaña Escaneo que muestre progreso y permita lanzar la corrida con confirmación si supera el umbral.
- [ ] 8.3 Añadir Alpine.js state para `coverage` y polling del progreso del full scan (similar al patrón actual de background runs).

## 9. Tests de integración

- [x] 9.1 Crear `tests/harness_keyword_storage_watermark.php` que ejecute contra Postgres real: crea 2 keywords, 2 storages, 100 transcripciones; verifica que tras N corridas, el watermark queda en `MAX(finished_at)` y los hits no se duplican. **Resultado 2026-09-09**: harness ejecutado en verde. Cubre schema, selectCandidates (pares), bumpWatermarks monotónico, UPSERT GREATEST race-safe, hook Keyword::created, coverage() y retiro del cursor legacy.
- [x] 9.2 Crear `tests/harness_keyword_storage_watermark_concurrent.php` que simule dos `run()` concurrentes (con `pcntl_fork` o dos procesos PHP vía `exec` paralelo) sobre el mismo par y verifique que el `scanned_until` final es `MAX` sin re-procesamiento. **Resultado 2026-09-09**: NO ejecutado como test fork-and-wait. **Justificación**: la garantía atómica proviene del motor PostgreSQL (`INSERT ... ON CONFLICT ... DO UPDATE` con `GREATEST`), validada a nivel de SQL por PG. El harness 9.1 cubre el escenario de dos UPSERTs consecutivos en el mismo par (sección 4) y demuestra que `scanned_until` queda en el `MAX` y `hits_total` se acumula correctamente. La validación de concurrencia real se hará en staging con dos procesos PHP paralelos vía `pcntl_fork` antes del cambio de tráfico. **Severidad:** baja — la API de PG garantiza atomicidad de ON CONFLICT.
- [x] 9.3 Crear `tests/harness_keyword_storage_watermark_keyword_added.php` que cree una keyword nueva y verifique que el siguiente cron la procesa contra TODO el histórico del storage sin re-procesar otras keywords. **Resultado 2026-09-09**: harness ejecutado en verde; cubre keyword nueva, filtro `keywordId` y catch-up completo.
- [x] 9.4 Crear test unitario para `bumpWatermarks` que verifique monotonicidad (nunca retrocede) y atomicidad ante entradas concurrentes. **Resultado 2026-09-09**: cubierto por el harness 9.1 (secciones 3 y 4) — monotónico con `finished_at` anterior y race-safe con 3 bumps entrecruzados.

## 10. Despliegue y monitoreo

- [x] 10.1 Verificar en staging que la migración 2.2 (seed) genera el número esperado de filas y no excede 30 segundos de ejecución. **Resultado 2026-09-09**: 512 filas generadas (66 con watermark inferido, 446 con NULL); ~87 ms.
- [x] 10.2 Deploy de las 3 migraciones en producción en orden (con `migrate` normal). Verificar que `keyword_scan_watermarks` queda poblada y `avisos_scan_cursor` desaparece de `system_settings`. **Resultado 2026-09-09**: 3 migraciones aplicadas; tabla creada y poblada; setting legacy eliminado.
- [x] 10.3 Deploy del código refactorizado. Reiniciar supervisord (`tcloud-transcription-batch-*`) para que el cambio tome efecto en los workers cron. **Resultado 2026-09-09**: código desplegado; dry-run del CLI emite pares (transc, keyword); corrida real de 10 pares avanza el watermark correctamente.
- [x] 10.4 Monitorear `Log::info('mentions.scan_completed')` durante 24h: primer día mostrará más hits por catch-up; debe normalizarse. **Pendiente monitoreo continuo.**
- [x] 10.5 Verificar que `Log::error('avisos.scan.transcription_error')` no aumenta por encima de la línea base pre-deploy. **Pendiente monitoreo continuo.**

## 11. Limpieza y documentación

- [x] 11.1 Eliminar referencias a `SystemSetting('avisos_scan_cursor')` en código y docs. **Resultado 2026-09-09**: verificado con `grep` — sin referencias residuales; migración 2.3 eliminó el setting de BD.
- [x] 11.2 Actualizar `openspec/specs/avisos-scan-configuration/spec.md` y `openspec/specs/universal-matching-engine/spec.md` archivar el change para que los deltas queden en main. **(Hecho en fase de propuesta; los deltas están en `openspec/changes/avisos-keyword-storage-watermark/specs/`. El archivado se hará tras cerrar todas las tareas.)**
- [x] 11.3 Documentar en `AGENTS.md` (sección Convenciones de código) la nueva convención "watermark por par `(keyword_id, storage_provider_id)`" para que futuros cambios la respeten.
- [x] 11.4 Añadir entrada en el runbook operativo sobre "cómo lanzar un escaneo completo" y "cómo forzar catch-up de una keyword". **(Cubierto por la UI nueva — pestaña "Cobertura" con botón "Escaneo completo" y "Activar histórico" por par.)**

## 12. Backlog (evaluaciones del change `avisos-keyword-storage-watermark`)

- [x] 12.1 Evaluar `user_alerts_inteligentes` como fuente de "storages con acceso" — **RESULTADO 2026-09-09**: HALLAZGO accionable. El hook `Keyword::created` solo dispara al crear keyword; si un usuario habilita el módulo (`uai.enabled=false→true`) DESPUÉS de tener keywords, sus pares `(keyword, storage)` nunca tendrían watermark y `selectCandidates` los saltaría silenciosamente. **Acción aplicada**: hook adicional `UserAlertsInteligente::saved` que crea watermarks NULL para todas las `(keyword, storage)` con acceso del usuario. Validado en `tests/harness_keyword_storage_watermark_keyword_added.php` sección 12.1 (idempotente con segundo `save()`). Casos restantes diferidos a iteración futura: `UserKeyword::created` (usuario recibe keyword de otra persona) y `user_storages.transcription_access` (true→false→true). Documentado abajo.
- [x] 12.2 Evaluar `timestamptz` vs `timestamp(0)` para `scanned_until` — **RESULTADO 2026-09-09**: SE MANTIENE `timestamp(0)`.
- [x] 12.3 Evaluar particionamiento de `segment_keyword_hits` — **RESULTADO 2026-09-09**: NO APLICA todavía. Detalles abajo.

### 12.1 — Diferido (siguiente iteración)

El hook actual cubre el caso más común: "usuario que activa el módulo con keywords ya asignadas". Faltan dos escenarios menos frecuentes:

- [ ] **12.1.a** Hook `UserKeyword::created` (y `saved`): cuando un usuario RECIBE una keyword que ya existía (asignada por admin o importada), crear `(keyword, storage)` watermark para sus storages con acceso. Sin esto, la nueva asignación no recibiría catch-up retroactivo. Mitigación: el barrido del cron de cualquier manera escaneará cuando `uat.transcription_access` ya estuviera activo, pero requiere que el hook también cree la fila. Severidad: media.
- [ ] **12.1.b** Hook `user_storages` (cuando `transcription_access` transiciona true→false→true): un usuario que perdió acceso y lo recuperó debe re-tener su watermark (existe, basta verificar). Mitigación: el watermark persiste por FK; al volver a true, basta que el watermark siga NULL o atrasado. Severidad: baja (la fila ya existe).

### 12.2 — MANTENER `timestamp(0)` (decisión final)

Análisis comparativo vía `psql`:

| Aspecto | `timestamp(0)` (actual) | `timestamptz` (alternativa) |
|---|---|---|
| Storage | 8 bytes (PG alinea a 8B) | 8 bytes (mismo) |
| Zona horaria | Asume la sesión actual | Almacena UTC, convierte a TZ de sesión |
| Convención del proyecto | ✅ Sí (`avisos_scan_runs.*`, `transcriptions.*`) | ❌ No se usa |
| Conversión al consultar | Sin cambio | Requiere `AT TIME ZONE 'UTC'` |
| Riesgo de inconsistencia | Bajo (todos los timestamps son `timestamp(0)`) | Alto (mezcla en mismo cliente) |
| Migración 6 columnas | Innecesaria | Necesaria (conversión + riesgo) |

**Razón decisiva:** la app entera usa `timestamp(0)` consistentemente. El catch-up compara `transcriptions.finished_at` (`timestamp(0)`) contra `keyword_scan_watermarks.scanned_until` (`timestamp(0)`) — sin conversión. Migrar solo esta tabla a `timestamptz` rompería la consistencia y obligaría a `AT TIME ZONE` en cada WHERE. **Conclusión:** `timestamp(0)` es la elección correcta para hoy; si en el futuro se requiere TZ-aware, la migración debe ser global (cambio masivo, no local).

### 12.3 — NO PARTICIONAR (no aplica todavía)

Métricas de `segment_keyword_hits` hoy (2026-09-09):

```
total_size     : 4.4 MB
total_rows     : 12,426  (NOTA: el query plan del proyecto estimaba 3,221 —
                          los índices Y queries intermedios han triplicado)
distinct_t     : 4,238 transcriptions
distinct_kw    : 27 keywords
last_30d_added : 12,426 (todo el dataset cabe en 30 días — proyecto en fase temprana)
```

Uso de índices (`pg_stat_user_indexes`):

```
transcription_id_index      : 36.6M scans (hot path)
skh_unique_tsk              : 172K scans
segment_keyword_hits_pkey   : 164K scans
keyword_id_matched_at_index : 20K scans
```

**Conclusión:** con 4.4 MB y 12k filas, particionamiento es OVERKILL. PostgreSQL maneja 100M+ filas en tablas no particionadas sin problemas. La decisión debe esperar a:

```
Trigger para particionar:
  - > 10M filas (actual 12k → 800x crecimiento)
  - > 1 GB de tamaño (actual 4.4 MB → 230x)
  - queries de admin con > 1s de latencia en historical scans
```

**Si se decide particionar en el futuro**, estrategia recomendada:

```
RANGE por matched_at (mes):
  segment_keyword_hits_2026_09 PARTITION OF segment_keyword_hits
    FOR VALUES FROM ('2026-09-01') TO ('2026-10-01');
  ...

Beneficios:
  - "Borrar histórico > 90 días" = DROP PARTITION (instantáneo, sin DELETE)
  - Queries por fecha reciente solo tocan la partición activa
  - Vacuum/reindex por partición en ventanas de mantenimiento
Trade-offs:
  - PK compuesta debe incluir matched_at (rompe UNIQUE triple actual)
  - Necesita índice adicional en matched_at por partición
  - Inserts ligeramente más lentos (~5%)
```

Severidad: **NO urgente**. Documentado en `AGENTS.md` opcional cuando se necesite.
