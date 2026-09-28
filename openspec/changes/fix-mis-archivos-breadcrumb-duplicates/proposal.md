## Why

Los usuarios reportan de forma **constante** que el breadcrumb de Mis Archivos muestra carpetas duplicadas consecutivas cuando navegan por jerarquías donde el mismo nombre aparece en niveles distintos (ej. `Alerta_Cartagena > 28092026 > 28092026`). El operador recibe reportes falsos diarios que consumen tiempo de soporte y erosionan la confianza en el módulo. La causa raíz es que la query recursiva de breadcrumbs en `FileController::index` (líneas 99-106) y la generación de la columna `path` en `StorageSyncService::createFileFromScan` (líneas 371-401) **no toleran** estados intermedios donde un folder `X` aparece como hijo de otro folder `X` por una mala asignación de `parent_id` durante el sync. Tales estados se materializan porque:

1. **Una corrida de sync concurrente** puede escribir `parent_id` antes de que `FileRegistry::ensure` haya consolidado una fila previa con la misma `path` y un padre distinto. La query de sync agrupa por `path` (`StorageSyncService:175-178`) pero `createFileFromScan` no valida que el `parent_id` recibido sea coherente con la fila agrupada en `$bdFiles[$fullRelativePath]`.
2. **Una carpeta renombrada en disco** sin re-sync completo puede dejar filas viejas con `parent_id` apuntando a una fila hermana con el mismo nombre que la nueva ubicación.
3. **`$storage->userStorages()->first()?->user_id ?? 1`** (línea 395) introduce variabilidad en `owner_id` cuando el storage no tiene `user_storages` registrado, pero no afecta directamente al bug; queda fuera de scope.

El síntoma UI no es "cosmético": se confunde con datos corruptos y dispara reportes operativos.

## What Changes

- **`files-folder-tree-integrity-guard`** (nueva capability): el módulo de archivos SHALL garantizar que la cadena de breadcrumb nunca muestre dos carpetas con el mismo nombre en posiciones consecutivas, y SHALL reparar `parent_id` cuando se detecte.
- **`storage-sync-parent-id-validator`** (nueva capability): el sync SHALL validar, antes de escribir, que un folder nuevo no crearía un ciclo auto-referente (padre con el mismo `name`), y SHALL abortar la creación con warning si lo haría.
- **Harness nuevo** `tests/harness_mis_archivos_breadcrumb_integrity.php`: 12+ aserciones que cubren el ciclo auto-referente, el breadcrumb duplicado, el caso legítimo `lib/lib` (tailwindcss), la regeneración de breadcrumbs con cache limpia, y la idempotencia del fix.

### Modificaciones al comportamiento actual

- `FileController::index` (líneas 94-110): post-cadena recursiva, **deduplica** segmentos consecutivos con el mismo `name`. Si se detectan duplicados, loguea `breadcrumb.dedup_consecutive` con `parent_id`, `storage_provider_id`, `path` y llama a un nuevo helper para **reparar** el `parent_id` del nodo conflictivo apuntando al abuelo efectivo (skipping al siguiente ancestro con `name` distinto).
- `StorageSyncService::createFileFromScan` (líneas 371-401): nueva pre-condición `assertNoSelfNestedName($parentId, $name)` que aborta con `Log::error` y no crea la fila si ya existe un folder con `name` igual y distinto `path` en la misma carpeta lógica.
- Nuevo comando `files:repair-breadcrumb-cycles [--apply]` que detecta cualquier folder con `name` igual al de su padre (auto-referencia en la cadena) o con un ciclo de 2+ niveles, y propone el re-parent correctivo.

## Impact

**Código afectado**:
- `app/app/Http/Controllers/FileController.php` (modificación ~25 líneas: helper de dedup + reparación en línea)
- `app/app/Services/StorageSyncService.php` (modificación ~15 líneas: validator)
- `app/app/Services/FileBreadcrumbIntegrityService.php` (nuevo, ~80 líneas): helper estático testeable con `assertNoSelfNestedName`, `repairInPlace`, `findCycles`
- `app/app/Console/Commands/FilesRepairBreadcrumbCyclesCommand.php` (nuevo, ~60 líneas)
- `app/tests/harness_mis_archivos_breadcrumb_integrity.php` (nuevo, harness ejecutable con `hbb_<hex>` y cleanup en `finally`)

**Riesgos**:
- (R1) Reparar `parent_id` en línea puede mover una carpeta que el usuario tenía bajo otro padre intencionalmente. Mitigación: el helper solo repara cuando la detección es **unívoca** (todos los ancestros hasta el abuelo resuelven al mismo `name`); en caso de ambigüedad solo loguea y deja la fila intacta.
- (R2) El validator en `createFileFromScan` puede romper un sync legítimo si por alguna razón DOS carpetas con el mismo `name` a la misma `path` canónica deben coexistir (ej. un storage con `parent_storage_id` heredando). Mitigación: el validator solo aborta cuando el `path` resultante es **idéntico**; si difiere (caso legítimo `lib/lib`), permite.
- (R3) False positive en el breadcrumb-dedup si dos carpetas con el mismo nombre son legítimamente consecutivas (ej. grabación diaria `25092026/25092026` por convención del operador). Mitigación: el harness cubre este escenario Y confirma que es legítimo antes de aprobar el merge.

**No migración de BD**, no cambio de schema, no migración requerida.

**Rollback**: `git revert <commit>` deja `FileController` con el comportamiento original; la integridad de datos no empeora porque el validator solo aborta, no borra.

**Capacidades removidas**: ninguna. **Capabilities nuevas**: 2 (ver arriba).
