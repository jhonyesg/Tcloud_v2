## Diagnóstico del estado actual (2026-09-28)

```
┌────────────────────────────────────────────────────────────────────────┐
│ Arquitectura vigente (post baseline 39961b3)                          │
├────────────────────────────────────────────────────────────────────────┤
│ [Disco NFS] ──scandir──▶ [StorageSyncService::syncFolder]            │
│                              │                                        │
│                              ▼                                        │
│                         [Tabla files en BD] ◀── query                 │
│                              │                                        │
│                              ▼                                        │
│                         [FileController::index]                       │
│                              │                                        │
│                              ▼                                        │
│                         [Vista Mis Archivos]                          │
│                                                                        │
│ Latencia: ~250 ms warm / ~1.2 s cold  (incluye CTE breadcrumb)        │
│ Estado stale: hasta 15 min si sync no ha corrido                      │
│ Discos fuera de línea: marca is_accessible=false PERMANENTE           │
└────────────────────────────────────────────────────────────────────────┘
```

```
┌────────────────────────────────────────────────────────────────────────┐
│ Arquitectura propuesta (FS-primero)                                   │
├────────────────────────────────────────────────────────────────────────┤
│ [Disco NFS] ──scandir on-demand──▶ [FilesystemListingService]         │
│          │                            │                               │
│          │                            ├──▶ [Vista Mis Archivos]       │
│          │                            │    (incluye badge FS)         │
│          │                            │                               │
│          │                            └──▶ permission-aware filter    │
│          │                                                          │
│          └──background matcher──────────────────────────────────┐    │
│                                                                   │    │
│                              ┌────────────────────────────────────┘   │
│                              ▼                                        │
│                         [Tabla files en BD]                            │
│                         (solo metadata operativa)                      │
│                                                                        │
│ Latencia: ~30-80 ms warm / ~50-150 ms cold                            │
│ Estado stale: 0 (lo que ves está en disco AHORA)                      │
│ Discos fuera de línea: error claro, sin estado fantasma              │
└────────────────────────────────────────────────────────────────────────┘
```

## Decisiones de diseño

### D1 — Lectura on-demand, sin cache de listado

`FilesystemListingService::list($storageId, $subPath)` ejecuta `scandir()` cada vez. NO cachea el resultado (alineado con `mis_archivos_no_cache_requisito` y la decisión de AGENTS.md "Mis Archivos NO debe usar caché porque genera y elimina información de forma continua"). El cache de Laravel solo se usa para:
- Resolver `file_id` por path cuando hace falta (TTL 60s).
- Recordar el último sync exitoso del matcher (para mostrar "última verificación: hace X").

Si `scandir` retorna más de `SystemSetting('mis_archivos.listing_limit')` entradas (default 500), el service devuelve las primeras 500 + `has_more=true` + `total_count`. La paginación es client-side (botón "cargar más" en la vista).

### D2 — Breadcrumb por dirname, no por CTE

```php
// ANTES (FileController.php:99-110):
WITH RECURSIVE parent_chain AS (
    SELECT id, name, parent_id, ... FROM files WHERE id = ?
    UNION ALL ...
)
SELECT id, name, storage_provider_id FROM parent_chain

// DESPUÉS (FilesystemListingService::parentChain):
$segments = explode('/', trim($subPath, '/'));
$chain = [];
foreach ($segments as $i => $seg) {
    $pathSoFar = implode('/', array_slice($segments, 0, $i + 1));
    $chain[] = ['name' => $seg, 'path' => $pathSoFar, 'has_file_id' => $this->resolveFileId($storageId, $pathSoFar)];
}
return array_reverse($chain);  // para mostrar root→current
```

Esto elimina completamente el bug del breadcrumb duplicado (no hay `parent_id` que consultarse).

### D3 — El matcher de metadata es OPTIMIZADO, no exhaustivo

Tres tipos de updates:
- **Hot** (cada sync): paths tocados por uploads/downloads recientes (trackeados en `Cache::get('mis_archivos:recent_paths')`).
- **Warm** (cada 15 min): recorrido por niveles 1-3 del árbol de cada storage (los más visibles).
- **Cold** (cada 6h): barrido completo con cursor paginado (`chunkById` + `ORDER BY id`).

Configurable vía `SystemSetting('mis_archivos.matcher_mode')` ∈ {`hot_only`, `hot_warm`, `hot_warm_cold`}.

### D4 — Permisos se filtran ANTES del render

`FilesystemPermissionGuard::filter(array $entries, int $userId)`:
- Si el usuario es admin → devuelve todo.
- Si no, obtiene `user_storage_ids` del usuario (de `user_storages`).
- Filtra por `storage_provider_id IN user_storage_ids`.
- Aplica además `read`/`full` check por storage individual.

El listado devuelto por `FilesystemListingService` ya viene filtrado. La UI nunca ve nombres que el usuario no debería poder acceder.

### D5 — Resolución de file_id bajo demanda con cache

```php
// ShareController::download($shareId)
$file = File::find($share->file_id);
if (!$file) {
    // El share quedó huérfano del matcher. Resolver por path.
    $resolved = FilesystemListingService::resolveFileId(
        storageProviderId: $share->storage_provider_id,
        path: $share->path_snapshot  // columna nueva, se llena al crear el share
    );
    if (!$resolved) {
        abort(404, 'Archivo ya no existe en disco');
    }
    $file = $resolved;
}
```

Cache 60s para `path → file_id` (TTL configurable vía `SystemSetting('mis_archivos.path_cache_ttl')`).

### D6 — Feature flag + canary

```
SystemSetting('mis_archivos.fs_primary_enabled') = false     # default, comportamiento actual
SystemSetting('mis_archivos.fs_primary_canary_storage_ids')  # = [134] en staging
```

Cuando `enabled=false`: `FileController::index` usa el código viejo (BD query).
Cuando `enabled=true`:
- Si `storage_id ∈ canary_storage_ids` → usa `FilesystemListingService`.
- Si no → usa código viejo (BD query).

Operador avanza el canary de a un storage por semana. Cuando todos los storages del usuario están en canary, se promueve `enabled=true` global.

### D7 — El campo `source` en la respuesta

```json
{
  "files": [
    {
      "id": 8468976,           // puede ser null si el matcher aún no ha pasado
      "name": "28092026",
      "path": "Bolivar/Alerta_Cartagena/28092026",
      "size": null,             // null cuando solo viene del filesystem sin match BD
      "mime_type": "folder",
      "is_folder": true,
      "file_modified_at": "2026-09-28T07:25:33-05:00",
      "source": "filesystem",   // "filesystem" | "database" | "mixed"
      "has_file_id": true,
      "actions": ["share", "delete", "rename"]  // depende de permisos
    }
  ],
  "pagination": { "page": 1, "per_page": 500, "total": 11, "has_more": false },
  "breadcrumbs": [...],
  "meta": {
    "fs_primary": true,
    "matcher_last_run": "2026-09-28T08:15:00-05:00",
    "matcher_pending": 14
  }
}
```

La UI pinta un badge "FS" en la esquina cuando `source === 'filesystem'`.

## Pieza por pieza

### A. `FilesystemListingService` (nuevo)

```php
namespace App\Services\MisArchivos;

class FilesystemListingService
{
    public function list(int $storageId, ?string $subPath, int $userId, int $limit = 500): array
    {
        $storage = StorageProvider::findOrFail($storageId);
        $absolute = $this->resolveAbsolutePath($storage, $subPath);
        
        // Pre-check: ¿disco disponible?
        if (!is_dir($absolute) || !is_readable($absolute)) {
            return [
                'files' => [],
                'pagination' => ['page' => 1, 'per_page' => $limit, 'total' => 0, 'has_more' => false],
                'breadcrumbs' => $this->parentChain($storageId, $subPath),
                'error' => 'path_missing',
            ];
        }
        
        // Detección de mount detached (delegada al MountGuard)
        if (($detached = app(MountGuard::class)->detachedAncestor($absolute)) !== null) {
            Log::warning('mis_archivos.mount_detached', [
                'storage_id' => $storageId,
                'mount_point' => $detached,
            ]);
            return [
                'files' => [],
                'pagination' => ['page' => 1, 'per_page' => $limit, 'total' => 0, 'has_more' => false],
                'breadcrumbs' => $this->parentChain($storageId, $subPath),
                'error' => 'mount_detached',
            ];
        }
        
        $entries = @scandir($absolute) ?: [];
        $result = [];
        $count = 0;
        foreach ($entries as $name) {
            if ($name === '.' || $name === '..') continue;
            $count++;
            if ($count > $limit) break;
            
            $entryAbs = $absolute . '/' . $name;
            $stat = @stat($entryAbs);
            if ($stat === false) continue;
            
            $result[] = $this->entryToArray($storageId, $subPath, $name, $stat, $entryAbs, $userId);
        }
        
        return [
            'files' => $result,
            'pagination' => [
                'page' => 1, 'per_page' => $limit,
                'total' => count($entries) - 2,
                'has_more' => count($entries) - 2 > $limit,
            ],
            'breadcrumbs' => $this->parentChain($storageId, $subPath),
            'meta' => [
                'fs_primary' => true,
                'matcher_last_run' => Cache::get('mis_archivos:matcher_last_run'),
                'matcher_pending' => (int) (DB::table('files')->where('storage_provider_id', $storageId)->whereNull('pending_deletion_at')->count() ?? 0),
            ],
        ];
    }
    
    public function parentChain(int $storageId, ?string $subPath): array
    {
        // ... ver D2
    }
    
    public function resolveFileId(int $storageId, string $path): ?int
    {
        return Cache::remember(
            "mis_archivos:path_lookup:{$storageId}:" . md5($path),
            (int) config('mis_archivos.path_cache_ttl', 60),
            fn() => DB::table('files')
                ->where('storage_provider_id', $storageId)
                ->where('path', $path)
                ->value('id')
        );
    }
    
    private function entryToArray(int $storageId, ?string $subPath, string $name, array $stat, string $absolute, int $userId): array
    {
        $relPath = ($subPath ? $subPath . '/' : '') . $name;
        $fileId = $this->resolveFileId($storageId, $relPath);
        $isFolder = ($stat['mode'] & 040000) === 040000;
        
        return [
            'id' => $fileId,
            'has_file_id' => $fileId !== null,
            'name' => $name,
            'path' => $relPath,
            'size' => $isFolder ? null : $stat['size'],
            'mime_type' => $isFolder ? 'folder' : $this->guessMime($name),
            'is_folder' => $isFolder,
            'file_modified_at' => Carbon::createFromTimestamp($stat['mtime'])->toIso8601String(),
            'source' => $fileId ? 'mixed' : 'filesystem',
            'permissions' => $this->permissionsForEntry($storageId, $userId),
            'actions' => $this->actionsForEntry($storageId, $userId, $fileId, $isFolder),
        ];
    }
}
```

### B. `FilesystemDbMatcher` (nuevo)

```php
class FilesystemDbMatcher
{
    public function matchStorage(int $storageId, string $mode = 'hot_warm'): array
    {
        $storage = StorageProvider::findOrFail($storageId);
        $stats = ['scanned' => 0, 'created' => 0, 'updated' => 0, 'missing_marked' => 0, 'missing_purged' => 0];
        
        // Modo hot: paths recientes desde cache
        if (in_array($mode, ['hot_only', 'hot_warm', 'hot_warm_cold'])) {
            $recentPaths = Cache::get("mis_archivos:recent_paths:{$storageId}", []);
            foreach ($recentPaths as $relPath) {
                $this->matchOne($storage, $relPath, $stats);
            }
        }
        
        // Modo warm: niveles 1-3
        if (in_array($mode, ['hot_warm', 'hot_warm_cold'])) {
            // scandir recursivo hasta nivel 3, matchea cada path
            $this->matchRecursive($storage, '', 0, 3, $stats);
        }
        
        // Modo cold: full sweep con cursor
        if ($mode === 'hot_warm_cold') {
            $this->markMissingAsPendingDeletion($storage, $stats);
        }
        
        Cache::put('mis_archivos:matcher_last_run', now()->toIso8601String(), 86400);
        Log::info('mis_archivos.matcher_run', $stats + ['storage_id' => $storageId, 'mode' => $mode]);
        return $stats;
    }
    
    private function matchOne(StorageProvider $storage, string $relPath, array &$stats): void
    {
        $absPath = rtrim($storage->base_path, '/') . '/' . ltrim($relPath, '/');
        if (!file_exists($absPath)) {
            $this->markMissing($storage->id, $relPath, $stats);
            return;
        }
        
        $existingId = DB::table('files')->where('storage_provider_id', $storage->id)->where('path', $relPath)->value('id');
        $stat = @stat($absPath);
        $isFolder = $stat && ($stat['mode'] & 040000) === 040000;
        
        if ($existingId) {
            DB::table('files')->where('id', $existingId)->update([
                'size' => $isFolder ? 0 : ($stat['size'] ?? 0),
                'is_folder' => $isFolder,
                'file_modified_at' => Carbon::createFromTimestamp($stat['mtime'] ?? time()),
                'pending_deletion_at' => null,
                'updated_at' => now(),
            ]);
            $stats['updated']++;
        } else {
            DB::table('files')->insert([
                'name' => basename($relPath),
                'path' => $relPath,
                'storage_provider_id' => $storage->id,
                'owner_id' => $storage->userStorages()->first()?->user_id ?? 1,
                'parent_id' => null,  // ya no se usa en modo FS-first
                'is_folder' => $isFolder,
                'size' => $isFolder ? 0 : ($stat['size'] ?? 0),
                'mime_type' => $isFolder ? 'folder' : $this->guessMime(basename($relPath)),
                'pending_deletion_at' => null,
                'file_modified_at' => Carbon::createFromTimestamp($stat['mtime'] ?? time()),
                'is_personal' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $stats['created']++;
        }
        $stats['scanned']++;
    }
    
    private function markMissing(int $storageId, string $relPath, array &$stats): void
    {
        $graceDays = (int) SystemSetting::get('mis_archivos.missing_grace_days', 7);
        DB::table('files')
            ->where('storage_provider_id', $storageId)
            ->where('path', $relPath)
            ->whereNull('pending_deletion_at')
            ->update(['pending_deletion_at' => now()]);
        $stats['missing_marked']++;
    }
}
```

### C. `MatchFilesystemToDatabaseCommand`

```
php artisan mis-archivos:match-fs-db {--storage=*} {--mode=hot_warm_cold}
```

Schedule: cada 5 min modo `hot_warm`, diario a las 03:00 Bogota modo `hot_warm_cold`.

### D. `FilesystemPermissionGuard`

```php
class FilesystemPermissionGuard
{
    public function filter(array $entries, int $userId): array
    {
        if ($userId <= 0) return [];
        $user = User::find($userId);
        if (!$user) return [];
        if ($user->isAdmin()) return $entries;
        
        $allowedStorages = $user->userStorages()->pluck('storage_provider_id')->toArray();
        return array_values(array_filter($entries, fn($e) => in_array($e['storage_provider_id'], $allowedStorages, true)));
    }
    
    public function canAccess(int $storageId, int $userId, string $permission = 'read'): bool
    {
        if ($userId <= 0) return false;
        $user = User::find($userId);
        if (!$user) return false;
        if ($user->isAdmin()) return true;
        return $user->hasStoragePermission($storageId, $permission);
    }
}
```

### E. Migration (una sola, reversible)

```php
// 2026_09_29_000000_add_pending_deletion_at_to_files.php
public function up(): void
{
    Schema::table('files', function (Blueprint $table) {
        $table->timestamp('pending_deletion_at')->nullable()->after('file_modified_at');
        $table->index(['storage_provider_id', 'pending_deletion_at'], 'files_pending_deletion_idx');
    });
}

public function down(): void
{
    Schema::table('files', function (Blueprint $table) {
        $table->dropIndex('files_pending_deletion_idx');
        $table->dropColumn('pending_deletion_at');
    });
}
```

### F. Modificación a `FileController::index`

```php
public function index(Request $request)
{
    // ... validación de usuario existente
    
    $fsEnabled = (bool) SystemSetting::get('mis_archivos.fs_primary_enabled', false);
    $canaryIds = SystemSetting::get('mis_archivos.fs_primary_canary_storage_ids', []);
    $useNewPath = $fsEnabled && (empty($canaryIds) || in_array($storageId, $canaryIds, true));
    
    if ($useNewPath) {
        return response()->json(
            app(FilesystemListingService::class)->list(
                storageId: $storageId,
                subPath: $subPath,
                userId: $user->id,
                limit: (int) SystemSetting::get('mis_archivos.listing_limit', 500),
            )
        );
    }
    
    // Código viejo (BD query) intacto
    return $this->indexDatabaseDriven($request);
}
```

## Compatibilidad

- `shares.file_id` sigue siendo FK. Cuando el share se crea, se intenta resolver `file_id` por `(storage_id, path)`; si no hay match, se guarda con `file_id=null` y el resolver de download cae a filesystem directo.
- `transcriptions.file_id` (`ON DELETE SET NULL`) sigue funcionando: si el archivo se borra físicamente, el matcher marca `pending_deletion_at` y tras la gracia borra la fila (lo que setea `transcriptions.file_id` a NULL via CASCADE).
- `user_storages` no cambia.
- `storage_providers.is_accessible` se vuelve opcional (el sistema lo consulta solo para diagnóstico, no para gating). El sync pasivo sigue marcándolo cuando corresponde, pero el listado FS-first funciona aunque el flag esté stale.

## Métrica de éxito

| Métrica | Antes | Después (FS-primario) |
|---|---|---|
| Latencia `GET /files?parent_id=X` warm | ~250 ms | ~50-80 ms |
| Latencia `GET /files?parent_id=X` cold | ~1.2 s | ~150-300 ms |
| Latencia breadcrumb (5 niveles) | ~180 ms (CTE) | ~10 ms (explode) |
| Casos breadcrumb duplicado | mitigados en baseline | 0 por diseño |
| `is_accessible` stale | hasta 11 días (storage 5 hoy) | irrelevante (FS directo) |
| Carpetas con NFS caído | bloqueadas por sync | error claro + listado vacío honesto |
| Drift filesystem↔BD | sin detectar | matcher cold detecta, purga tras gracia |
| Throughput upload | ~5/s (BD insert + sync) | ~15/s (BD insert opcional) |

## Plan de despliegue

1. **Fase 0 — Benchmark** (1 día): comando `mis-archivos:benchmark --storage=134` mide latencia scandir vs query BD. Datos para validar hipótesis.
2. **Fase 1 — Modo pasivo** (3 días): implementar `FilesystemListingService` y `FilesystemDbMatcher` sin tocar `FileController`. Matcher corre en background, BD se mantiene caliente.
3. **Fase 2 — Canary** (1 semana): activar `fs_primary_enabled=true` solo para storage 134 con `canary_storage_ids=[134]`. Monitor de logs `mis_archivos.mount_detached`, `mis_archivos.listing_error`.
4. **Fase 3 — Expansión** (2 semanas): añadir storages al canary uno a uno. Validar performance en carpetas grandes (5k+ archivos).
5. **Fase 4 — Global** (1 día): `fs_primary_enabled=true`, `canary_storage_ids=[]`. Modo BD queda como fallback de emergencia.
6. **Fase 5 — Cleanup** (1 día, opcional): `git revert` del cambio `fix-mis-archivos-breadcrumb-duplicates` ya integrado en baseline 39961b3 si todo va bien (la columna `parent_id` queda libre para usos futuros, no se borra).
