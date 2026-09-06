## Context

El explorador hoy hace hard-delete con `FileController@destroy` → `deleteRecursive`. El `@rmdir` silencia errores y `$folder->delete()` corre igual, lo que combinado con `StorageSyncService` (que recrea lo que sigue en disco) produce la persistencia parcial que el usuario reportó. La papelera cambia el contrato: delete pasa a ser soft, el sync aprende a respetar `is_trashed`, y la purga programada usa un guardarraíl tipo `SessionService::cleanOrphans` (abort si ratio alto).

Restricciones heredadas (ver `AGENTS.md`):
- Auth SIEMPRE vía `session('user_id')`, NUNCA `auth()->user()`.
- Tests de integración: harness `tests/harness_*.php` contra Postgres + Redis reales.
- Módulos existentes: `app/app/Modules/Correo`, `app/app/Modules/GrabacionesPuntuales` → siguen el patrón que adoptamos.

El estado actual del código que tocamos:
- `app/app/Http/Controllers/FileController.php:392` (`destroy`) y `967` (`deleteRecursive`) / `989` (`deleteFile`).
- `app/app/Services/StorageSyncService.php:213-280` (loop de sync) y `398` (`createFileFromScan`).
- `app/app/Models/File.php` (fillable, casts).
- `app/routes/web.php` (rutas existentes).
- `app/routes/console.php` (scheduler).
- `app/resources/views/layouts/app.blade.php` (sidebar).
- `app/app/Http/Controllers/PublicShareController.php` (endpoint `/s/{token}` → debe responder 410 Gone si el archivo está trashed).
- `app/config/` (sin `trash.php` aún).

## Goals / Non-Goals

**Goals:**
- Replace `FileController@destroy` body with soft-trash, keeping the same HTTP contract (200 + JSON).
- Hard-delete (purga) en un solo lugar (`PapeleraService::hardDelete`) con guardarraíl de ratio.
- StorageSyncService respeta `is_trashed` sin tocar la lógica de scan existente.
- UI sidebar "Papelera" + vista dedicada `/papelera`.
- Cron diario `trash:purge`.

**Non-Goals:**
- Re-arquitectura del sync (solo añadir el respeto por `is_trashed`).
- Storage dedicado para papelera (queda para v2).
- Limpieza de archivos físicos en soft-trash (solo flag en BD).
- Cambio del modelo de shares (solo añadir el caso 410).
- Internacionalización de los textos de la papelera (vienen del español del layout principal).

## Decisions

### D1. Soft-trash strategy: `is_trashed` flag + `parent_id=NULL` + `original_parent_id`

**Por qué:** Los listados del explorador ya filtran por `parent_id`. Nulificarlo al trashing hace que la fila desaparezca automáticamente de cualquier carpeta sin tocar la query de listado. `original_parent_id` guarda de dónde viene para el restore.

**Alternativas:**
- `is_folder`-style flag sin tocar parent_id: requiere añadir `WHERE is_trashed=false` a TODAS las queries de listado → alta invasividad, riesgo de regresión.
- Borrar físicamente la fila y crear una `trash_files` paralela: obliga a duplicar toda la metadata (size, mime, owner, storage_provider_id) y romper FK de shares/transcriptions.
- **Decidido:** soft flag + parent_id null + original_parent_id. Cero cambios en queries existentes; nuevo scope Eloquent `File::trashed()` para los casos que sí necesitan consultar la papelera.

### D2. Migration como columnas sueltas + índice parcial

```sql
ALTER TABLE files
  ADD COLUMN deleted_at         TIMESTAMP NULL,
  ADD COLUMN is_trashed         BOOLEAN NOT NULL DEFAULT false,
  ADD COLUMN original_parent_id BIGINT NULL REFERENCES files(id) ON DELETE SET NULL;

CREATE INDEX files_trash_sweep_idx
  ON files (deleted_at) WHERE is_trashed = true;
```

**Por qué:** El índice parcial `(deleted_at) WHERE is_trashed=true` mantiene la purga O(retention) incluso cuando la tabla `files` tiene millones de filas (la papelera es siempre una fracción pequeña). El FK con `ON DELETE SET NULL` evita huérfanos si el padre se purga antes que un hijo.

### D3. `PapeleraService` como single-source-of-truth para hard-delete

**Por qué:** Hoy `deleteRecursive` vive dentro de `FileController`. Si la papelera llama directamente, la lógica de borrado se duplica y se desincroniza. La papelera extrae la lógica a un servicio y `FileController@destroy` se queda como wrapper que solo llama a `softTrash`.

**Diseño:**

```php
namespace App\Modules\Papelera\Services;

class PapeleraService
{
    public function softTrash(File $file, ?int $actorUserId): void
    {
        // marca is_trashed, deleted_at, parent_id=NULL, original_parent_id
        // si es folder: recursivo a hijos
    }

    public function restore(File $file, ?int $actorUserId): File
    {
        // resuelve parent destino (original si existe, sino root)
        // resuelve colisión de nombre (-restored-<ts>)
        // limpia flags
    }

    public function hardDelete(File $file, ?int $actorUserId): void
    {
        // skip si isFileLinked (de StorageSyncService)
        // llama deleteRecursive/deleteFile (sin @)
        // decrementa quota si storage personal
    }

    public function purgeExpired(int $batchSize, float $maxRatio): int
    {
        // Cache::lock
        // count + ratio check
        // chunkById, hardDelete cada uno con skip si isFileLinked
    }

    public function emptyFor(User $user): int
    {
        // vaciar papelera del usuario
    }
}
```

**Alternativas:**
- Tirar todo dentro de `FileController@destroy` con un flag `?hard=1`: el controller se vuelve spaghetti.
- Usar Laravel SoftDeletes trait: descartado, no encaja con la FK parent_id (el trait usa `deleted_at` y un global scope, pero queremos que `parent_id=NULL` también, lo cual requiere override).

### D4. `StorageSyncService` modificación mínima: scope + skip

**Tres puntos de inyección:**

1. `createFileFromScan` → antes de insertar, comprobar si ya existe una fila con `is_trashed=true` para ese path. Si existe, **no crear** y dejarla como está. Marcar el path en una blacklist interna para que el loop de update tampoco la toque.
2. El loop de update (línea ~224) → si la fila matched tiene `is_trashed=true`, skip (un `unset($bdFiles[$fullRelativePath])` evita que entre al prune).
3. El bucle de huérfanos (línea ~270) → añadir filtro `where('is_trashed', false)` al cargar `$bdFiles`.

**Por qué mínimo:** no queremos tocar el scanner ni el prune guard. Solo añadir "trashed rows son intocables".

### D5. Cron con `Schedule::command('trash:purge')->daily()` + lock

```php
// app/routes/console.php
use Illuminate\Support\Facades\Schedule;

Schedule::command('trash:purge')
    ->dailyAt('03:17')          // hora rara para no coincidir con otros crons
    ->withoutOverlapping(15)     // lock de 15 min, mismo patrón que otras tareas largas
    ->runInBackground();
```

El comando `trash:purge`:
1. Adquiere `Cache::lock('trash:purge', 600)`.
2. `count()` de candidatos vs `count()` total del storage.
3. Si `candidatos / total > max_ratio` → abort + log `trash.purge.aborted_mass_delete`.
4. `chunkById(500, fn($row) => $service->hardDelete($row, null))`.

### D6. UI: módulo `app/app/Modules/Papelera/`

```
app/app/Modules/Papelera/
├── Controllers/PapeleraController.php
├── Services/PapeleraService.php
├── Commands/TrashPurgeCommand.php
├── resources/views/index.blade.php
└── routes.php  (registrado desde AppServiceProvider)
```

**Vista `index.blade.php`** (Alpine.js, mismo patrón que `files/index.blade.php` pero minimal):

| Estado Alpine | Tipo | Default |
|---|---|---|
| `items` | array | `[]` (del response de `/papelera`) |
| `selectedItems` | array | `[]` |
| `currentPage` | int | 1 |
| `confirming` | string\|null | null ('restore'\|'hardDelete'\|'empty') |
| `searchDays` | number | 0 (filtro "menos de X días restantes") |

Acciones:
- `restore(item)` → `POST /papelera/{id}/restore` → recarga.
- `hardDelete(item)` → `confirm()` + `DELETE /papelera/{id}` → recarga.
- `emptyAll()` → `POST /papelera/empty` con doble confirm → recarga.

**Sidebar (`layouts/app.blade.php`):**

```blade
<a href="/papelera" class="...">
  <span>Papelera</span>
  @if($trashCount = app(\App\Modules\Papelera\Services\PapeleraService::class)->countFor(session('user_id')))
    <span class="badge {{ $trashHasUrgent ? 'bg-red-500' : 'bg-slate-500' }}">
      {{ $trashCount }}
    </span>
  @endif
</a>
```

(Se calcula en `AppServiceProvider::boot()` o en una view-composer, cacheado por 60s para no martillar la BD.)

### D7. PublicShareController: 410 Gone en lugar de redirect/404

```php
// app/app/Http/Controllers/PublicShareController.php:show()
$file = $share->file;
if ($file->is_trashed) {
    return response()->json([
        'error' => 'file_in_trash',
        'message' => 'El archivo fue movido a la papelera por su propietario.',
    ], 410);
}
```

**Por qué 410 Gone:** semánticamente correcto (el recurso existió pero ya no está accesible). No es 404 porque el share token SÍ existe; no es 403 porque el visitante no tiene por qué saber de la papelera.

### D8. Validación con harness PHP, no PHPUnit

`tests/harness_papelera_lifecycle.php` ejercita:
1. Crea storage temp + 1 carpeta con 1 archivo.
2. `softTrash(carpeta)` → assertea fila con flags correctos, archivo en disco intacto.
3. `syncFolderWithReport` sobre el padre → assertea que la carpeta NO reaparece (sync respeta flag).
4. `restore(carpeta)` → assertea parent_id recuperado, sin colisión.
5. `hardDelete(carpeta)` → assertea fila + archivo en disco borrados.
6. `purgeExpired` con un item fechado artificialmente viejo → assertea purga.

Cleanup en `finally` con el patrón ya conocido.

## Risks / Trade-offs

- **[Riesgo] El sync skip por `is_trashed` puede ocultar bugs reales** → Si por alguna razón una fila válida queda con `is_trashed=true` por accidente (bug en softTrash), nunca más se repara vía sync → **Mitigación**: el admin tiene una UI o comando para "forzar re-sincronización" (forzar null del flag → el sync ya la puede tocar). Documentar en `AGENTS.md`.
- **[Riesgo] Race entre `trash:purge` y un restore** → El purge marca candidatos y luego itera. Si entre el count y el delete un usuario restaura uno de los candidatos, el purge lo borra igual → **Mitigación**: el purge adquiere `Cache::lock('trash:purge')` y los endpoints de restore/restore-all adquieren `Cache::lock('trash:user:{id}')` distintos. Sin colisión de locks. El restore verifica `is_trashed=true` antes de actuar, así que si el purge ya lo borró, el restore falla limpio con 404.
- **[Riesgo] Cuota no se descuenta al soft-trash, sí al hard-delete** → Si el usuario tiene `personal_used_bytes` contando el archivo, el "espacio ocupado" no refleja la papelera → **Mitigación documentada**: aceptar esto como comportamiento. El usuario ve "X archivos en papelera, Y GB liberados cuando se purguen". Se documenta en la UI.
- **[Riesgo] La purga es destructiva** → Un bug en el filtro de `retention_days` podría borrar todo → **Mitigación**: el guardarraíl de ratio aborta si el batch es sospechoso. Además el purge corre solo UNA vez al día y SOLO sobre filas con `is_trashed=true` (no toca filas activas). Doble defensa.
- **[Trade-off] No movemos archivos a `.trash/` físico** → Más simple y rápido, pero los archivos en papelera siguen ocupando el mismo espacio en su storage original. Si la papelera se llena, ocupa del storage principal. **Aceptado para MVP**; v2 podría añadir storage dedicado o mover a subdir.
- **[Trade-off] No limpiamos el `@rmdir` legacy de `deleteRecursive`** → El bug latente persiste en hard-delete. La papelera llama a esa misma función, pero el guardarraíl de `isFileLinked` evita el peor caso (borrar algo con shares/transcriptions). Para el MVP aceptamos; follow-up en change aparte.

## Migration Plan

### Deploy
1. **Migración primero**: `php artisan migrate` añade columnas + índice. **No es destructiva** (default false/null), reversible con rollback.
2. **Deploy de código**: el módulo Papelera + cambios en FileController/StorageSyncService/PublicShareController.
3. **`config:clear`** y `composer dump-autoload`.
4. **Smoke test manual**: crear archivo, eliminar, ver en `/papelera`, restaurar, ver en carpeta original. Hard-delete manual desde `/papelera`. Forzar un purge corriendo `php artisan trash:purge` con un item viejo.

### Rollback
1. `git revert <commit>` → vuelve el código al estado previo.
2. `php artisan migrate:rollback --step=1` → quita las columnas.
3. No hay datos trash existentes en producción todavía (la migración es nueva), así que rollback limpio.

### Post-deploy monitoring (24h)
```bash
# 1. Cero 500s en FileController@destroy (debería seguir siendo 200)
grep "FileController.*destroy" app/storage/logs/laravel.log | grep -v " 200 " | wc -l

# 2. Items en papelera creciendo de forma esperada
PGPASSWORD=cloud123 psql ... -c "SELECT COUNT(*) FROM files WHERE is_trashed=true;"

# 3. Cron trash:purge corrió OK
grep "trash.purge" app/storage/logs/laravel.log | tail -10

# 4. Cero resets por sync tocando filas trashed
grep "storage_sync.*trashed\|trashed.*sync" app/storage/logs/laravel.log | wc -l  # debe ser 0
```

## Open Questions

Ninguna que bloquee la propuesta. Decisiones tomadas en esta sesión con el usuario:
- Soft-flag (no mover en disco). ✓
- Retention global en config (default 15). ✓
- Restore a original con sufijo en colisión. ✓
- Módulo nuevo `app/app/Modules/Papelera/`. ✓

Follow-ups NO bloqueantes (quedan como ideas anotadas):
- Storage dedicado para papelera (v2 si se llena el principal).
- Restore en lote desde el listado normal (no solo desde la vista papelera).
- Email de aviso al owner cuando algo entra a papelera.
- Limpiar el bug latente de `@rmdir` en `deleteRecursive` (cambio aparte).
