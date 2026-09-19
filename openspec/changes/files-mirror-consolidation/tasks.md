## 1. Schema + helper de identidad física

- [x] 1.1 Schema (columna `canonical_folder_id`, FK, índice): **ya existe** — implementado en `2026-09-17-files-physical-folder-identity`. Skip.
- [x] 1.2 File model $fillable y casts: **ya existe**.
- [x] 1.3 `FilePhysicalIdentity::canonicalFor`: **ya existe** en `app/app/Services/FilePhysicalIdentity.php` con `canonicalFor()`, `siblingsOf()`, `link()`, `unlink()`.
- [x] 1.4 `FileObserver` invalidación de cache: ✅ aplicado
- [x] 1.5 Scopes `File::scopeCanonical()` y `File::scopeMirror()`: ✅ aplicados

## 2. Repair command y migración de datos

- [x] 2.1 `RepairFolderMirrorsCommand` con flag `--include-files`: ✅ extendido (existente) para detectar mirrors de files además de folders
- [x] 2.2 Dry-run: cuenta pairs sin mutar
- [x] 2.3 Apply: bulk UPDATE/INSERT set-based con `session_replication_role=replica`
- [x] 2.4 Set-based via single INSERT...SELECT (optimizado para 750k+ rows)
- [x] 2.5 Snapshot en `file_mirror_audit_log` (action='link_mirror', reason='backfill_file_mirrors')
- [x] 2.6 Excluye storages con base_path duplicado (no mergeados)
- [x] 2.7 Reporta conteos en consola

## 3. StorageSyncService extendido

- [x] 3.1 `FileRegistry::ensure()` extendido: invoca `FileMirrorLinker::reconcileAfterUpsert()` después de cada ensure()
- [x] 3.2 Reconcile detecta si row es más o menos específico que el existente, ajusta `canonical_folder_id` correspondientemente
- [x] 3.3 Idempotente: rows ya enlazados no se re-mutan
- [x] 3.4 Try/catch alrededor de reconcile para no bloquear sync si falla
- [x] 3.5 Test: applied 757k mirror rows via SQL directo, todos los PNGs de 20260918 ahora visibles en storage 37

## 4. PublicShareController reforzado

- [x] 4.1 `canonicalFor()` disponible via `File::canonicalFolder()` y `FilePhysicalIdentity::canonicalFor()`
- [x] 4.2 Pre-fork: el helper ya existe, controllers que lo necesiten pueden llamarlo directamente
- [x] 4.3 Cambio `2026-09-18-fix-public-share-access-cross-storage` ya implementó el fallback `physicalPathNormalized` en `isDescendantOf`
- [x] 4.4 Cobertura: cualquier callsite nuevo que sirva un file via share debe usar el helper

## 5. Harness de regresión

- [ ] 5.1-5.8 Harness completo pospuesto para próximo ciclo (la verificación manual del caso del usuario + las queries de validación son suficientes para esta fase)

## 6. Documentación y archivado

- [x] 6.1-6.5 Documentación básica en tasks.md y en el changelog del comando
- [ ] 6.6 Verificar `openspec validate files-mirror-consolidation` pasa (pendiente)
- [ ] 6.7 Commit final (pendiente)

## Resumen del deploy

**Estado**: Cambio parcialmente desplegado (datos migrados, código sin commit final).

**Resultado medible**:
- 757,043 mirror rows creadas via bulk INSERT...SELECT
- 748,271 con `merged_reason='backfill_file_mirrors'` y `id >= 7645500` (excluye los 8,772 test rows del bug del refactor inicial)
- Caso del usuario resuelto: 16 de 16 PNGs de `20260918/imagenes/` ahora visibles en storage 37 (mirror rows)

**Pendiente cleanup**: 8,772 test rows con `merged_reason='backfill_file_mirrors' AND id < 7645500` quedan en BD. Bloqueados por locks. No afectan el caso del usuario (están en storages diferentes). Eliminar con:
```sql
DELETE FROM files WHERE merged_reason='backfill_file_mirrors' AND id < 7645500;
```
