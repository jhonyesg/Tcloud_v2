## Context

Ver `proposal.md` para la motivación. Este documento cubre el "cómo" técnico.

**Estado actual (a 2026-09-19)**:
- `files` tiene 2.26M filas. Para folders físicos que viven en sub-storages, existen mirror rows en el storage padre con paths relativos — pero solo a nivel de folder, NO de files.
- Ejemplo concreto: storage 44 (`206 Portafolio`, base=`/Prensa/Portafolio`) tiene 16 PNGs en `20260918/imagenes/`. Storage 37 (`200_Diarios`, base=`/Prensa`) tiene una mirror row del folder `Portafolio/20260918/imagenes/` (vacía de hijos en el mirror porque el sync no propaga files al mirror).
- AGENTS.md menciona este problema como `folder identity canónica para shares` (`files-physical-folder-identity` + `share-folder-canonical-wiring`). La parte de shares ya está hecha; falta la consolidación de mirror rows para files.
- La columna `files.canonical_folder_id` existe (FK self-ref), pero `merged_into_id` (mencionada en AGENTS.md como parte del PR 1) NO existe aún en el schema actual.

## Goals / Non-Goals

**Goals**:
- Una sola API (`FilePhysicalIdentity::canonicalFor`) para resolver canónico desde cualquier callsite.
- `merged_into_id` como columna en `files` (NULL = canónico, NOT NULL = mirror → canónico).
- `StorageSyncService` extendido para crear mirror rows de files (no solo folders).
- Comando `files:repair-folder-mirrors` para reparar histórico.
- Harness con 6 secciones que cubren los 4 módulos afectados (Mis Archivos, Share, Editor, Sync).

**Non-Goals**:
- No eliminamos mirror rows existentes (se mantienen, solo se les agrega `merged_into_id` apuntando al canónico).
- No cambiamos el modelo de permisos (`user_storages` sigue siendo la fuente de verdad).
- No creamos una columna desnormalizada `canonical_id` redundante — el helper hace el cálculo on-demand con cache opcional.
- No tocamos `transcriptions.file_id`, `shares.file_id`, etc. — siguen apuntando al mismo `id`, el helper resuelve canónico cuando es relevante.

## Decisions

### Decisión 1: Columna `merged_into_id` en `files`

**Por qué una columna explícita**: queries cross-storage necesitan saber rápidamente si una fila es canónica o mirror, y cómo llegar al canónico. Una columna indexada es O(1) vs un JOIN por `physical_path_normalized` cada vez.

**Schema**:
```php
$table->unsignedBigInteger('merged_into_id')->nullable();
$table->foreign('merged_into_id')->references('id')->on('files')->onDelete('set null');
$table->index('merged_into_id');
```

**Por qué `ON DELETE SET NULL`**: si el canónico se borra (papelera o hard-delete), los mirrors quedan como huérfanos — pero el siguiente sync puede re-vincularlos o marcarlos como canónicos nuevos. NO cascade-delete los mirrors.

**Alternativas**:
- Vista materializada que compute `merged_into_id` on-read. **Rechazada** por complejidad de mantenimiento y latencia.
- Tabla separada `file_mirror_relations`. **Rechazada** por simplicidad — denormalizar en `files` es más directo para queries `WHERE merged_into_id IS NULL`.

### Decisión 2: `physicalPathNormalized()` como clave de identidad

**Definición**: `lower(rtrim(base_path_snapshot || '/' || path))`. Dos archivos con la misma identidad normalizada son el mismo archivo físico.

**Por qué `lower` + `rtrim`**: case-insensitive (Linux es case-sensitive pero el operador podría tener nombres con mayúsculas por error) y trim de trailing slash (algunos paths vienen con `/` extra).

**Por qué `base_path_snapshot` y NO `storage_providers.base_path`**: snapshot está denormalizado en `files` (mantenido por `FileObserver` desde 2026-09-17), por lo que el cálculo de identidad NO paga un JOIN por lookup.

**Alternativa**: usar hash MD5 del path. **Rechazada** porque introduce un segundo concepto y complica debugging (no se puede ver el path normalizado en una query).

### Decisión 3: Helper `FilePhysicalIdentity::canonicalFor` con cache opcional

```php
public static function canonicalFor(File $file): File
{
    if ($file->merged_into_id === null) return $file;
    $cached = Cache::get("file:canonical:{$file->id}");
    if ($cached) return File::find($cached);
    
    $visited = [$file->id];
    $current = $file;
    while ($current->merged_into_id !== null) {
        if (in_array($current->merged_into_id, $visited, true)) break; // anti-ciclo
        $visited[] = $current->merged_into_id;
        $current = File::find($current->merged_into_id);
        if (!$current) break;
    }
    
    Cache::put("file:canonical:{$file->id}", $current->id, 300);
    return $current;
}
```

**Cache**: TTL 300s, invalidar en `FileObserver::saved` cuando `merged_into_id` cambia.

**Por qué recursivo simple (no CTE SQL)**: legible, testeable, y el cache mitiga el costo. Si el performance lo exige en producción, se puede cambiar a CTE con un único query, pero empezar simple.

### Decisión 4: Sync extendido para mirror rows de files

`StorageSyncService::syncFolder` después del cambio:
1. Para cada file en el folder escaneado, calcular `physicalPathNormalized`.
2. Buscar si existe OTRA fila con la misma `physicalPathNormalized` pero distinto `storage_provider_id` (mirror existente).
3. Si existe y el storage actual es más específico (mayor profundidad en cadena `parent_storage_id`), marcarlo como canónico y al otro como mirror (`merged_into_id = id del canónico`).
4. Si no existe, crear la fila como canónica (`merged_into_id = NULL`).
5. Si existe y el storage actual es MENOS específico, NO crear mirror row aquí (la fila mirror ya existe en otro storage).

**Por qué "más específico gana"**: el storage más cercano a la raíz física es la fuente de verdad. Storage 44 (`206 Portafolio` base=`/Prensa/Portafolio`) es más específico que storage 37 (`200_Diarios` base=`/Prensa`), por eso sus files son canónicos y los de storage 37 son mirrors.

**Alternativa**: dejar que el usuario elija canónico manualmente. **Rechazada** porque el patrón "más específico gana" es determinístico y testeable, y coincide con el comportamiento del sistema actual (storage 37 solo tiene mirror rows).

### Decisión 5: Comando `files:repair-folder-mirrors` con dry-run y apply

**Algoritmo**:
1. Para cada storage padre (los que tienen `parent_storage_id` apuntando a otro storage O son raíz):
2. Calcular su `base_path`.
3. Para cada sub-storage descendiente, calcular `base_path` también.
4. Para cada fila canónica en el sub-storage (folders Y files), calcular `physicalPathNormalized` y verificar si existe una mirror row en el storage padre.
5. Si no existe, listarla (dry-run) o crearla (apply).

**Set-based**: usar un único `INSERT INTO files (...) SELECT ... FROM files WHERE ...` con sub-storages, no iterar archivo por archivo.

**Snapshot pre-cambio**: insertar en `file_mirror_audit_log` (action=`create_mirror`) antes del bulk INSERT.

**Rendimiento**: ~5-15 min para 500k files con `session_replication_role = replica` + `statement_timeout=0`.

### Decisión 6: `PublicShareController::canonicalFor` antes de servir

Ya parcialmente implementado en `2026-09-18-fix-public-share-access-cross-storage`. Reforzar contrato:

```php
// PublicShareController::show
$file = FilePhysicalIdentity::canonicalFor($share->file);
$filePath = $storage->base_path . '/' . $file->path;
// ... sirve $filePath (canónico) no el path del share
```

**Por qué**: si el share fue creado sobre un mirror row, descargar usando el path del mirror puede apuntar a un directorio que el storage del mirror NO tiene acceso a nivel de filesystem. Canonizar garantiza que el path resuelto es el real.

## Risks / Trade-offs

**[R1] Comando de reparación toca ~500k filas** → Mitigación: snapshot pre-cambio en `file_mirror_audit_log`, transacción atómica, `session_replication_role = replica` para evaluación set-based.

**[R2] Queries cross-storage ahora requieren JOIN adicional** → Si una query hace `WHERE storage_provider_id = X` y `X` es un storage padre, no obtendrá los files reales (que viven en `X+1`...). Esto es comportamiento ACTUAL del sistema (siempre lo fue) y no es regresión. Documentar en AGENTS.md.

**[R3] `merged_into_id` requiere mantenerlo sincronizado** → Si se crea un file nuevo en un storage que ya tiene un mirror de ese path, ¿cuál es canónico? La regla "más específico gana" lo resuelve, pero el código debe aplicarla consistentemente en sync, FileController::create, PublicShareController::upload. Mitigación: helper central `Files::resolveCanonicalOnCreate(File $file): int|null`.

**[R4] Cache stale de canonicalFor** → TTL 300s, invalidado por `FileObserver::saved` cuando `merged_into_id` cambia. Aceptable para el caso (no frecuente).

**[R5] Migración de BD lenta en producción** → 2.26M filas para agregar `merged_into_id = NULL` (default), índice. ALTER TABLE con `ADD COLUMN ... NULL` es O(1) por fila. Estimación: 30-60s.

**[R6] Auditoría vía grep puede dar falsos positivos** → El helper `FilePhysicalIdentity::canonicalFor` debe ser la ÚNICA API para resolver canónico en código nuevo. Grep de `merged_into_id` inverso debe confirmar cobertura.

## Migration Plan

### Pre-deploy

```bash
# 1. Audit: cuántos files están en sub-storages sin mirror rows?
PGPASSWORD=cloud123 psql -h 127.0.0.1 -U cloud -d tcloudstorage -c "
SELECT sp.id, sp.name, (SELECT COUNT(*) FROM files f WHERE f.storage_provider_id = sp.id AND f.deleted_at IS NULL AND f.is_trashed = false) AS files_in_storage,
       (SELECT COUNT(*) FROM storage_providers WHERE parent_storage_id = sp.id) AS children
FROM storage_providers sp
WHERE sp.parent_storage_id IS NOT NULL
ORDER BY files_in_storage DESC LIMIT 10;
"

# 2. Dry-run del comando
cd app && php artisan files:repair-folder-mirrors --dry-run
```

### Deploy

1. Merge PR (código + migration + harness + cambios en share controller)
2. `php artisan config:cache`
3. `php artisan migrate` (crea columna `merged_into_id` + índice)
4. `php artisan files:repair-folder-mirrors --apply` (5-15 min)
5. `systemctl reload php84-php-fpm`
6. `cd app && php tests/harness_files_mirror_consolidation.php` (debe pasar)

### Rollback

```bash
# 1. Revertir código (git revert)
cd /www/wwwroot/cloud.mediaserver.com.co/Tcloud_v2 && git revert <commit>
systemctl reload php84-php-fpm

# 2. Revertir migración (DROP COLUMN merged_into_id + índice)
cd app && php artisan migrate:rollback --step=1
```

Si el rollback falla: las mirror rows creadas en el paso 4 del deploy siguen existiendo, pero sin `merged_into_id` no se usan (porque el helper `canonicalFor` retorna el mismo row cuando `merged_into_id IS NULL`). El comportamiento post-rollback es idéntico al pre-deploy.

## Open Questions

- ¿El helper `canonicalFor` debe ser eager (al guardar un file) o lazy (al consultar)? **Decisión**: lazy con cache 300s. El eager agregaría overhead a cada save sin beneficio observable.
- ¿La columna debe llamarse `merged_into_id` o `canonical_id`? **Decisión**: `merged_into_id` por consistencia con el diseño documentado en AGENTS.md y los tests existentes que ya referencian este nombre.
